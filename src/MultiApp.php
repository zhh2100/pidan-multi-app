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

	/**
	 * 应用名称
	 * @var string
	 */
	protected $name;

	/**
	 * 应用名称
	 * @var string
	 */
	protected $appName;

	/**
	 * 应用路径
	 * @var string
	 */
	protected $path;

	public function __construct(App $app)
	{
		$this->app  = $app;
		$this->name = $this->app->http->getName();
		$this->path = $this->app->http->getPath();
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
	 * 解析多应用
	 * @return bool
	 */
	protected function parseMultiApp(): bool
	{
		$defaultApp = $this->app->config->get('app.default_app') ?: 'index';
		//app('http')->name('应用')指定了应用   或通过入口文件名指定
		if ($this->name || ( ($scriptName = $this->getScriptName()) && !in_array($scriptName, ['index', 'router', 'pidan']))) {
			$appName = $this->name ?: $scriptName;
			$this->app->http->setBind();
		//没指定再识别
		} else {
			// 自动多应用识别
			$this->app->http->setBind(false);
			$appName       = null;
			$this->appName = '';

			$bind = $this->app->config->get('app.domain_bind', []);
			//通过域名绑定识别
			if (!empty($bind)) {
				// 获取当前子域名
				$subDomain = $this->app->request->subDomain();
				$domain    = $this->app->request->host(true);

				if (isset($bind[$domain])) {//完整域名
					$appName = $bind[$domain];
					$this->app->http->setBind();
				} elseif (isset($bind[$subDomain])) {//子域名 a.b.c.com   中a.b
					$appName = $bind[$subDomain];
					$this->app->http->setBind();
				} elseif (isset($bind['*'])) {//泛域名
					$appName = $bind['*'];
					$this->app->http->setBind();
				}
			}
			
			//域名没绑定   再通过映射识别
			if (!$this->app->http->isBind()) {
				$path = $this->app->request->pathinfo();// index/blog/index
				$map  = $this->app->config->get('app.app_map', []);//允许的app app_map=>['huotai'=>admin,*=>home,...]   或 ['admin','blog',...] 
				$deny = $this->app->config->get('app.deny_app_list', []);
				$name = current(explode('/', $path));//取根路径

				if (strpos($name, '.')) {
					$name = strstr($name, '.', true);
				}
				
				//映射中有 映射这格式[访问到的=>实际应用] [‘home’=>'index','huotai'=>'admin',]
				if (isset($map[$name])) {
					//通过匿名函数取实际访问的应用
					if ($map[$name] instanceof Closure) {
						$result  = call_user_func_array($map[$name], [$this->app]);
						$appName = $result ?: $name;
					} else {
						$appName = $map[$name];
					}
				// 从url中取到了name,map格式为['admin','blog',...]中不存在的与禁止的应用    报错，最好入口文件指定应用，其次通过域名指定
				} elseif ($name && (false===array_search($name, $map) || in_array($name, $deny))) {//map与deny是这格式   ['index','admin','blog']
					throw new RuntimeException('app not exists:' . $name);
				} elseif ($name && isset($map['*'])) {
					$appName = $map['*'];
				//没取到name或map格式为['admin','blog',...]  通过域名访问就没有pathinfo
				} else {
					$appName = $name ?: $defaultApp;
					$appPath = $this->path ?: $this->app->getBasePath() . $appName . DIRECTORY_SEPARATOR;

					if (!is_dir($appPath)) {
						$express = $this->app->config->get('app.app_express', false);
						if ($express) {
							$this->setApp($defaultApp);
							return true;
						} else {
							return false;
						}
					}
				}
				//大约118行  根据访问路径pathinfo取到  设置url的root,  pathinfo去除name只保留   控制器与方法      
				if ($name) {
					$this->app->request->setRoot('/' . $name);
					$this->app->request->setPathinfo(strpos($path, '/') ? ltrim(strstr($path, '/'), '/') : '');
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
		$this->appName = $appName;
		$this->app->http->name($appName);

		$appPath = $this->path ?: $this->app->getBasePath() . $appName . DIRECTORY_SEPARATOR;

		$this->app->setAppPath($appPath);
		// 设置应用命名空间
		$this->app->setNamespace($this->app->config->get('app.app_namespace') ?: 'app\\' . $appName);

		if (is_dir($appPath)) {
			$this->app->setRuntimePath($this->app->getRuntimePath() . $appName . DIRECTORY_SEPARATOR);
			$this->app->http->setRoutePath($this->getRoutePath());

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
			$this->app->config->load($appPath.'/config.php');
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
