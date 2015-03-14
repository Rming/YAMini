<?
/**
 * ENVIRONMENT
 * production / development
 */
define('ENVIRONMENT',"development");

/**
 * BASE_PATH & APP_PATH
 */
define('BASE_PATH',  dirname(__FILE__).DIRECTORY_SEPARATOR);

define('APP_PATH',   BASE_PATH.'app'.DIRECTORY_SEPARATOR);

define('DEFAULT_CONTROLLER', 'home');
define('DEFAULT_METHOD',     'index');
define('PHP_EXT',            '.php');
define('REWRITE_EXT',        '.html');

/**
 * exception_handler
 */
set_exception_handler(function(Exception $e){
    $code = $e->getCode();
    switch ($code) {
        case 404:
            header("Location: /404");
            break;
        case 405;
            header("Location: /405");
            break;
        default:
            exit($e->getMessage());
            break;
    }
});

/**
 * whether dispay errors base on ENVIRONMENT
 */
switch (ENVIRONMENT) {
    case 'production':
        ini_set("display_errors", "off");
        error_reporting(0);
        break;
    case 'development':
        ini_set("display_errors", "On");
        error_reporting(E_ALL);
        break;
    default:
        throw new Exception("Error Processing ENVIRONMENT");
        break;
}

/**
 * autolader
 */
spl_autoload_register(function($class_name){
    $class_file = BASE_PATH.str_replace('\\','/',$class_name).PHP_EXT;
    if(file_exists($class_file)) {
        require(BASE_PATH.str_replace('\\','/',$class_name).PHP_EXT);
    }
});

class core
{
    use uri;
    private static $instance = null;
    private static $routers  = [];
    private function __construct()
    {
        self::router('GET/POST/HEAD');
        self::router('PUT/DELETE/TRACE/CONNECT/OPTIONS/PATCH/COPY/LINK/UNLINK/PURGE',null,function(){
            throw new Exception("Method Not Allowed", 405);
        });
    }
    public static function get_instance()
    {
        if (self::$instance === null) {
            return self::$instance = new self;
        }
    }

    public function router($method = 'GET/POST/HEAD', $pattern = null, $handler = null)
    {
        self::$routers[] = [
            'method'  => $method,
            'pattern' => $pattern,
            'handler' => $handler,
        ];
        return self::$routers;
    }

    public function run()
    {
        $routers = self::$routers;

        $request_method = isset($_SERVER['REQUEST_METHOD'])?$_SERVER['REQUEST_METHOD']:false;

        if ($request_method === false) throw new Exception("Error Processing REQUEST_METHOD");

        $routers = array_filter($routers, function($router) use ($request_method){
            $select = in_array( strtoupper($request_method) , explode('/',strtoupper($router['method'])) ) || ($router['method'] == '*');
            $select = $select && preg_match('/'.$router['pattern'].'/i', '/'.implode('/', uri::param()) );
            return $select;
        });

        $router = array_pop($routers);

        if (!$router) throw new Exception("Method Not Allowed", 405);

        self::process($router);

    }


    private function process($router)
    {
        extract($router);

        if (!is_callable($handler)) {
            if (is_string($handler)) {
                $uri_params = array_filter(explode('/', $handler));
            } else {
                $uri_params = uri::param();
            }

            $directory_name       = array_shift($uri_params);
            $directory_controller = APP_PATH.'controllers'.DIRECTORY_SEPARATOR;
            $directory_real       = $directory_controller.$directory_name.DIRECTORY_SEPARATOR;

            if (!is_dir($directory_real)) {
                array_unshift($uri_params, $directory_name);
                $directory_name = null;
                $directory_real = $directory_controller;
            }

            switch (count($uri_params)) {
                case 0:
                    array_push($uri_params, DEFAULT_CONTROLLER,DEFAULT_METHOD);
                    break;
                case 1:
                    array_push($uri_params, DEFAULT_METHOD);
                    break;
                default:
                    $uri_params_chunked = array_chunk($uri_params, 2, false);
                    $uri_params         = array_shift($uri_params_chunked);
                    break;
            }

            $class_name = array_shift($uri_params);
            if (!file_exists($directory_real.$class_name.PHP_EXT)) {
                throw new Exception("Page Not Found", 404);
            }
            $class_real  = str_replace(DIRECTORY_SEPARATOR, '\\', str_replace(BASE_PATH, '', $directory_real.$class_name));
            $controller  = new $class_real();
            $method_name = array_shift($uri_params);

            if (is_callable([$controller, $method_name])){
                $processor = [$controller, $method_name];
            } else {
                throw new Exception("Page Not Found", 404);
            }

        } else {
            $processor = $router['handler'];
        }

        call_user_func_array($processor,uri::param_assoc());

    }


}

trait uri
{
    public static function param($n = false)
    {
        $request_uri = isset($_SERVER['REQUEST_URI'])?$_SERVER['REQUEST_URI']:false;
        if ($request_uri===false) {
            throw new Exception("Error Processing REQUEST_URI");
        }
        $request_uri = mb_substr($request_uri, 0, mb_stripos($request_uri, REWRITE_EXT, 0, 'utf-8')?:strlen($request_uri), 'utf-8');
        if (!preg_match('/\/[0-9a-z_~\:\.\-\/]*/i', $request_uri, $matches)) {
            throw new Exception("Error Processing REQUEST_URI");
        } else {
            $uri_params = array_filter(explode('/', trim(array_shift($matches), '/')));
            if (is_numeric($n)) {
                $n = $n-1;
                if (isset($uri_params[$n])) {
                    return $uri_params[$n];
                } else {
                    return false;
                }
            } else {
                return $uri_params;
            }
        }
    }

    public static function param_assoc($start = 3)
    {
        $uri_params = self::param();
        if ($start > count($uri_params) || !$uri_params) {
            $uri_params_assoc = [];
        } else {
            $uri_params_origin = $uri_params;
            array_shift($uri_params);
            $uri_params_assoc = [];
            array_map(function($k, $v) use (&$uri_params_assoc){$uri_params_assoc[$k]=$v;}, $uri_params_origin, $uri_params);
            $assoc_index      = array_flip(range($start-1,  2*count($uri_params_origin), 2));
            $assoc_keys       = array_intersect_key(array_keys($uri_params_assoc), $assoc_index);
            $uri_params_assoc = array_intersect_key($uri_params_assoc, array_flip($assoc_keys));
        }

        return $uri_params_assoc;
    }
}

trait load
{
    public static function view($file = null, $data = [], $return = false)
    {
        $view_path = APP_PATH.'views'.DIRECTORY_SEPARATOR.$file;
        is_array($data)?extract($data):null;
        ob_start();
        include($view_path);
        if ($return) {
            $buffer = ob_get_contents();
            @ob_end_clean();
            return $buffer;
        }
        ob_end_flush();
    }
}

$app = core::get_instance();

$app->router('*','^\/404$', function(){
    header("HTTP/1.1 404 Not Found");
    load::view('404.tpl', ['title'=>'Oops...']);
});

$app->router('*','^\/405$', function(){
    header("HTTP/1.1 405 Method Not Allowed");
    echo "405 Page Not Found";
});


$app->router('GET','^\/$', function(){
    echo "halo world!";
});

$app->router('GET','^\/hello$', function(){
    echo "halo world!";
});


$app->router('GET','^\/welcome$', function(){
    echo "welcome!";
});

$app->router('GET','^\/rmingwang$', '/home');

/*
$app->router('GET','^\/(.*)$', function(){
    echo "site cloesed!";
});
*/

$app->run();


/**
 * $app->router($method = 'GET/POST/HEAD',$pattern = null, $handler = null)
 * $handler = String/Closure
 * uri::param_assoc($start = 3)
 * uri::param($n = false)
 *
 * load::view($file = null,$data = [],$return = false)
 *
 *
 */




