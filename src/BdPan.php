<?php

namespace Azhida\BaiduwangpanApi;

class BdPan
{
    protected $AppID = '';
    protected $Appkey = '';
    protected $Secretkey = '';
    protected $RedirectUri = 'oob'; // 回调地址：如 http://localhost/baidu_api/get_code.php , 默认 oob
    protected $rtype = 1; // 文件命名策略：1 表示当path冲突时，进行重命名；2 表示当path冲突且block_list不同时，进行重命名；3 当云端存在同名文件时，对该文件进行覆盖

    public function __construct($AppID = '', $Appkey = '', $Secretkey = '', $RedirectUri = '', $rtype = 1)
    {
       $this->AppID = $AppID;
       $this->Appkey = $Appkey;
       $this->Secretkey = $Secretkey;
       $this->RedirectUri = $RedirectUri;
       $this->rtype = $rtype;

        // 参数异常处理 todo
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
        $token = $this->request($url, 'get', $query);
        $token['expires_time'] = time() + $token['expires_in'];

        $f = fopen('token.json', 'w');
        fwrite($f, json_encode($token));
        fclose($f);
        return $token;
    }

    public function request($url, $type = 'GET', $query = [], $json = [], $headers = [])
    {
        $params = [];
        if (!empty($query)) $params['query'] = $query;
        if (!empty($json)) $params['data'] = $json;
        if (!empty($headers)) $params['headers'] = $headers;
        $client = new \GuzzleHttp\Client();
        $response = $client->request($type, $url, $params)->getBody()->getContents();
        return json_decode($response, true) ?? null;
    }
}