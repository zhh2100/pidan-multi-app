<?php
declare (strict_types = 1);

namespace pidan\app;

use Closure;
use RuntimeException;
use pidan\App;
use pidan\Request;
use pidan\Response;

/**
 * 多应用模式支持
 */
class MultiApp
{

	/** @var App */
	protected $app;

	protected $http;

	/**
	 * 应用路径
	 * @var string
	 */
	protected $path;

	public function __construct()
	{
		$this->app  = app();
		$this->http=$this->app->make('http');
		$this->path = $this->http->getPath();
	}

	/**
	 * 多应用解析
	 * @access public
	 * @param Request $request
	 * @param Closure $next
	 * @return Response
	 */
	public function handle($request, Closure $next)
	{
		if (!$this->parseMultiApp()) {
			return $next($request);
		}

		return $this->app->middleware->pipeline('app')
			->send($request)
			->then(function ($request) use ($next) {
				return $next($request);
			});
	}

	/**
	 * 获取路由目录
	 * @access protected
	 * @return string
	 */
	protected function getRoutePath(): string
	{
		return $this->app->getAppPath() . 'route' . DIRECTORY_SEPARATOR;
	}

	/**
	 * 解析多应用  注意$name是pathinfo中取的    $appName函数取到的    最好入口文件指定应用，其次通过域名指定
	 * @return bool
	 */
	protected function parseMultiApp(): bool
	{
		$request=$this->app->make('request');
		$http=$this->http;
		$defaultApp = app('config')->get('app.default_app') ?: 'index';
		//http->name('应用')指定应用   或入口文件名指定    不动pathinfo
		if (($http_name=$http->getName()) || ( ($scriptName = $this->getScriptName()) && !in_array($scriptName, ['index', 'router', 'pidan']))) {
			$appName = $http_name ?: $scriptName;
			$http->setBind();
		}
		//没指定再识别$name 主要流程就是从pathinfo中取   然后从中去除
		else{
			// 自动多应用识别
			$appName       = null;

			$bind = app('config')->get('app.domain_bind', []);
			//通过域名绑定识别
			if (!empty($bind)) {
				// 获取当前子域名
				$subDomain = $request->subDomain();
				$domain    = $request->host(true);

				if (isset($bind[$domain])) {//完整域名
					$appName = $bind[$domain];
					$http->setBind();
				} elseif (isset($bind[$subDomain])) {//子域名 a.b.c.com   中a.b
					$appName = $bind[$subDomain];
					$http->setBind();
				} elseif (isset($bind['*'])) {//泛域名
					$appName = $bind['*'];
					$http->setBind();
				}
			}
			
			//域名没绑定   再通过映射识别
			if (!$http->isBind()) {
				$path = $request->pathinfo();// index/blog/index
				$map  = app('config')->get('app.app_map', []);//允许的app app_map=>['index'=>home,*=>home,...]   或 ['admin','blog',...]
				$deny = app('config')->get('app.deny_app_list', []);
				$name = current(explode('/', $path));//从pathinfo取到的appName

				if (strpos($name, '.')) {
					$name = strstr($name, '.', true);
				}
				
				//映射中有 映射这格式[访问到的=>实际应用] [‘home’=>'index','user'=>'user',]
				if (isset($map[$name])) {
					//通过匿名函数取实际访问的应用
					if ($map[$name] instanceof Closure) {
						$result  = call_user_func_array($map[$name], [$this->app]);
						$appName = $result ?: $name;
					}
					else {
						$appName = $map[$name];
					}

				}
				// 从pathinfo中取到了name,map格式为[‘home’=>'index','user'=>'user',]   如果存在映射，上面if已运行（如[‘home’=>'index'）或禁止访问
				elseif ($name && (false!==array_search($name, $map) || in_array($name, $deny))) {//deny是这格式   ['index','admin','blog']
					throw new RuntimeException('app not exists:' . $name);
				}
				elseif ($name && isset($map['*'])) {
					$appName = $map['*'];
					//没取到$appName或map格式为['admin','blog',...]  通过域名访问就没有pathinfo
				}
				else {
					$appName = $name ?: $defaultApp;
					$appPath = $this->path ?: $this->app->getBasePath() . $appName . DIRECTORY_SEPARATOR;

					if (!is_dir($appPath)) {
						$express = app('config')->get('app.app_express', false);
						if ($express) {
							$this->setApp($defaultApp);
							return true;
						} else {
							return false;
						}
					}
				}

				//大约118行  设置url的root,  pathinfo去除name=app只保留   控制器与方法
				if ($name) {
					$request->setRoot('/' . $name);//这是访问的url   http://domain.com/index
					//如果存在name=app,去除  留ctrl/act
					$request->setPathinfo(strpos($path, $name)===false ? $path : ltrim(strstr($path, '/'), '/'));
				}
			}
		}

		$this->setApp($appName ?: $defaultApp);
		return true;
	}

	/**
	 * 获取当前运行入口名称
	 * @access protected
	 * @codeCoverageIgnore
	 * @return string
	 */
	protected function getScriptName(): string
	{
		if (isset($_SERVER['SCRIPT_FILENAME'])) {
			$file = $_SERVER['SCRIPT_FILENAME'];
		} elseif (isset($_SERVER['argv'][0])) {
			$file = realpath($_SERVER['argv'][0]);
		}

		return isset($file) ? pathinfo($file, PATHINFO_FILENAME) : '';
	}

	/**
	 * 设置应用
	 * @param string $appName
	 */
	protected function setApp(string $appName): void
	{
		$this->http->name($appName);

		$appPath = $this->path ?: $this->app->getBasePath() . $appName . DIRECTORY_SEPARATOR;

		$this->app->setAppPath($appPath);
		// 设置应用命名空间
		$this->app->setNamespace(app('config')->get('app.app_namespace') ?: 'app\\' . $appName);

		if (is_dir($appPath)) {
			$this->app->setRuntimePath($this->app->getRuntimePath() . $appName . DIRECTORY_SEPARATOR);
			$this->http->setRoutePath($this->getRoutePath());

			//加载应用
			$this->loadApp($appName, $appPath);
		}
	}

	/**
	 * 加载应用文件
	 * @param string $appName 应用名
	 * @return void
	 */
	protected function loadApp(string $appName, string $appPath): void
	{
		if (is_file($appPath . 'common.php')) {
			include_once $appPath . 'common.php';
		}

		if (is_file($appPath.'/config.php')) {
			$this->app->make('config')->load($appPath.'/config.php');
		}

		if (is_file($appPath . 'event.php')) {
			$this->app->loadEvent(include $appPath . 'event.php');
		}

		if (is_file($appPath . 'middleware.php')) {
			$this->app->middleware->import(include $appPath . 'middleware.php', 'app');
		}

		if (is_file($appPath . 'provider.php')) {
			$this->app->bind(include $appPath . 'provider.php');
		}

		// 加载应用默认语言包
		//$this->app->loadLangPack($this->app->lang->defaultLangSet());
	}

}
