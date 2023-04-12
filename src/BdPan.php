<?php

namespace Azhida\BaiduwangpanApi;

class BdPan
{
    protected $config = [];
    protected $AppID = '';
    protected $Appkey = '';
    protected $Secretkey = '';
    protected $RedirectUri = 'oob'; // 回调地址：如 http://localhost/baidu_api/get_code.php , 默认 oob
    protected $rtype = 1; // 文件命名策略：1 表示当path冲突时，进行重命名；2 表示当path冲突且block_list不同时，进行重命名；3 当云端存在同名文件时，对该文件进行覆盖
    protected $FileFragmentSize = 4; // 分片上传的单个文件片段大小，单位 M，默认 4M

    public function __construct($config = [])
    {
        $this->config = $config;
        $this->AppID = $config['AppID'];
        $this->Appkey = $config['Appkey'];
        $this->Secretkey = $config['Secretkey'];
        $this->RedirectUri = $config['RedirectUri'];
        if (isset($config['rtype'])) {
            $this->rtype = $config['rtype'];
        }
        if (isset($config['FileFragmentSize'])) {
            $this->FileFragmentSize = $config['FileFragmentSize'];
        }

        // 参数异常校验 todo
    }

    public function authorize()
    {
        if (isset($_GET['code'])) {
            $this->makeToken($_GET['code']);
        } else {
            $state = md5(md5(time()));
            $url = 'http://openapi.baidu.com/oauth/2.0/authorize?response_type=code&client_id=' . $this->Appkey . '&redirect_uri=' . $this->RedirectUri . '&scope=basic,netdisk&state=' . $state;
            header("Location:" . $url);
        }
    }

    /**
     * 上传文件
     * 基本流程
     * - 对文件切片
     * - 预上传，拿到 uploadid
     * - 分片上传，
     * - 创建文件，
     * @param $source_file string 源文件，全路径
     * @param $file_name string 上传到网盘后的文件名称
     * @return bool|string
     */
    public function upload($source_file, $file_name)
    {
        $cutFileRes = $this->cutFile($source_file, $this->FileFragmentSize);
        $block_list = $cutFileRes['block_list'];
        $cut_files = $cutFileRes['files'];

        // 预上传
        $preCreateRes = $this->preCreate($source_file, $file_name, $block_list);

        // 分片上传
        $superFileRes = $this->superFile($source_file, $file_name, $preCreateRes['uploadid'], $cut_files);

        // 创建文件
        $createFileRes = $this->createFile($source_file, $file_name, $preCreateRes['uploadid'], $block_list);

        return $createFileRes;
    }

    /**
     * 预上传
     * @param $source_file string 源文件，全路径
     * @param $file_name string 上传到网盘的文件名，相对路径
     * @param $block_list string 分片 md5 json 串
     * @return bool|mixed|string|null
     */
    public function preCreate($source_file, $file_name, $block_list)
    {
        $access_token = $this->getToken();

        $data = [
            'path' => $file_name,
            'size' => filesize($source_file),
            'rtype' => $this->rtype,
            'isdir' => 0,
            'autoinit' => 1,
            'block_list' => $block_list
        ];

        $url = 'https://pan.baidu.com/rest/2.0/xpan/file?method=precreate&access_token=' . $access_token;
        $res = $this->curlPost($url, $data);
        $res = json_decode($res, true);
        return $res;
    }

    /**
     * 分片上传
     * @param $source_file
     * @param $file_name
     * @param $uploadid string 预上传返回的
     * @param array $cut_files 分片后的文件集
     * @return bool|string
     */
    public function superFile($source_file, $file_name, $uploadid, $cut_files = [])
    {
        $access_token = $this->getToken();
        $query = [
            'type' => 'tmpfile',
            'path' => $file_name,
            'uploadid' => $uploadid,
            'partseq' => 0,
        ];

        $res = [];

        foreach($cut_files as $key => $cut_file) {
            $query['partseq'] = $key;
            //拼接url
            $url = 'https://d.pcs.baidu.com/rest/2.0/pcs/superfile2?method=upload&access_token=' . $access_token . '&';
            $url .= http_build_query($query);

            $post_data = [
                'file' => curl_file_create($cut_file)
            ];
            $res[] = $this->curlPost($url, $post_data);
        }

        return $res;
    }

    /**
     * 创建文件
     * @param $source_file
     * @param $file_name
     * @return bool|string
     */
    public function createFile($source_file, $file_name, $uploadid, $block_list)
    {
        $access_token = $this->getToken();
        $data = [
            'path' => $file_name,
            'size' => filesize($source_file),
            'isdir' => 0,
            'block_list' => $block_list,
            'uploadid' => $uploadid,
            'rtype' => $this->rtype,
        ];
        $url = 'https://pan.baidu.com/rest/2.0/xpan/file?method=create&access_token=' . $access_token;
        return $this->curlPost($url, $data);
    }

    // 获取 access_token
    public function getToken()
    {
        if (!file_exists($this->getTokenFile())) {
            die('未授权');
        }
        $token = file_get_contents($this->getTokenFile());
        $token = json_decode($token, true);
        if (empty($token)) {
            // todo 抛出异常
            die('token 失效，请重新授权');
        }

        if ($token['expires_time'] < time()) {
            // 过期失效了，刷新token
            $token = $this->refreshToken();
        }
        return $token['access_token'];
    }

    //生成本地令牌token
    public function makeToken($code)
    {
        if (empty($code)) {
            // todo 抛出异常
            die('授权失败');
        }
        $url = 'https://openapi.baidu.com/oauth/2.0/token';
        $query = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'client_id' => $this->Appkey,
            'client_secret' => $this->Secretkey,
            'redirect_uri' => $this->RedirectUri,
        ];
        $url .= '?' . http_build_query($query);
        $token = $this->httpGet($url);
        $token = json_decode($token, true);
        $token['expires_time'] = time() + $token['expires_in'];

        $f = fopen($this->getTokenFile(), 'w');
        fwrite($f, json_encode($token));
        fclose($f);
        return $token;
    }

    // 刷新 token
    public function refreshToken()
    {
        if (!file_exists($this->getTokenFile())) {
            die('token 失效');
        }
        $token = file_get_contents($this->getTokenFile());
        $token = json_decode($token, true);
        if (empty($token)) {
            // todo 抛出异常
            die('token 失效，请重新授权');
        }
        $url = 'https://openapi.baidu.com/oauth/2.0/token';
        $query = [
            'grant_type' => 'refresh_token',
            'refresh_token' => $token['refresh_token'],
            'client_id' => $this->Appkey,
            'client_secret' => $this->Secretkey,
        ];
        $url .= '?' . http_build_query($query);
        $token = $this->httpGet($url);
        $token = json_decode($token, true);
        $token['expires_time'] = time() + $token['expires_in'];

        $f = fopen($this->getTokenFile(), 'w');
        fwrite($f, json_encode($token));
        fclose($f);

        return $token;
    }

    // 获取 token 文件地址
    public function getTokenFile()
    {
        return dirname(__FILE__) . '/token.json';
    }

    // todo 后续封装优化
    // public function request($url, $type = 'GET', $query = [], $json = [], $headers = [])
    // {
    //     $params = [];
    //     if (!empty($query)) $params['query'] = $query;
    //     if (!empty($json)) $params['data'] = $json;
    //     if (!empty($headers)) $params['headers'] = $headers;
    //     $client = new \GuzzleHttp\Client();
    //     $response = $client->request($type, $url, $params)->getBody()->getContents();
    //     return json_decode($response, true) ?? null;
    // }

    public function httpGet($url)
    {
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_TIMEOUT, 500);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, FALSE);
        curl_setopt($curl, CURLOPT_URL, $url);
        $res = curl_exec($curl);
        curl_close($curl);
        return $res;
    }


    public function curlPost($url, $data = array())
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, FALSE);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        $output = curl_exec($ch);
        curl_close($ch);
        return $output;
    }

    /**
     * 文件切片
     * @param $source_file string 源文件
     * @param $single_size integer 单片文件大小，单位 M ，默认 4M , 如果你是会员，可以把这个值调大，具体看百度网盘文档
     * @return array
     */
    public function cutFile($source_file, $single_size = 4)
    {
        $single_size = $single_size * 1024 * 1024;

        $block_list = [];
        $files = []; // 切割后的文件集

        $path = dirname($source_file) . '/temp_files/';
        if (!file_exists($path)) {
            mkdir($path, 0777);
        }

        $i  = 0; // 分割的块编号
        $fp  = fopen($source_file, "rb");      //要分割的文件
        while(!feof($fp)){
            $file_name = $path . basename($source_file) . '.' . $i;
            $handle = fopen($file_name,"wb");
            $content = fread($fp,$single_size);
            fwrite($handle, $content);
            $block_list[] = md5($content);
            $files[] = $file_name;
            fclose($handle);
            unset($handle);
            $i++;
        }
        fclose ($fp);

        return [
            'block_list' => json_encode($block_list),
            'files' => $files,
        ];
    }
}