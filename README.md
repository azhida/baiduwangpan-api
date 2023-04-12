<h1 align="center"> baiduwangpan-api </h1>

<p align="center"> 百度网盘API.</p>


## Installing

```shell
$ composer require azhida/baiduwangpan-api -vvv
```

## Usage

```php
$config = [
    'AppID' => '',
    'Appkey' => '',
    'Secretkey' => '',
    'RedirectUri' => 'http://localhost/bd_pan/get_code',
    'rtype' => 1,
    'FileFragmentSize' => 4, // 分片上传的单个文件片段大小，单位 M，默认：4M
];
$bdPan = new \Azhida\BaiduwangpanApi\BdPan($config);

// 授权
$bdPan->authorize();

// 上传文件
$bdPan->upload('./111.zip', '/apps/111.zip');
```

## Contributing

You can contribute in one of three ways:

1. File bug reports using the [issue tracker](https://github.com/azhida/baiduwangpan-api/issues).
2. Answer questions or fix bugs on the [issue tracker](https://github.com/azhida/baiduwangpan-api/issues).
3. Contribute new features or update the wiki.

_The code contribution process is not very formal. You just need to make sure that you follow the PSR-0, PSR-1, and PSR-2 coding guidelines. Any new code contributions must be accompanied by unit tests where applicable._

## License

MIT