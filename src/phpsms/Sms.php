<?php
namespace Toplan\PhpSms;

use Toplan\TaskBalance\Balancer;

/**
 * Class Sms
 * @package Toplan\PhpSms
 */
class Sms
{
    /**
     * sms send task name
     */
    const TASK = 'PhpSms';

    /**
     * log agent`s name
     */
    const LOG_AGENT = 'Log';

    /**
     * agents instance
     */
    protected static $agents;

    /**
     * agents`s name
     * @var
     */
    protected static $agentsName = [];

    /**
     * agents`s config
     * @var
     */
    protected static $agentsConfig = [];

    /**
     * whether to enable queue
     * @var bool
     */
    protected static $enableQueue = null;

    /**
     * queue work
     * @var null
     */
    protected static $howToUseQueue = null;

    /**
     * sms already pushed to queue
     * @var bool
     */
    protected $pushedToQueue = false;

    /**
     * hook handlers
     * @var array
     */
    protected static $hookHandlers = [
        "beforeRun" => null,
        "afterRun"  => null,
    ];

    /**
     * sms data
     * @var array
     */
    protected $smsData = [
        'to' => null,
        'templates' => [],
        'content' => '',
        'templateData' => [],
        'voiceCode' => null,
    ];

    /**
     * construct
     */
    public function __construct()
    {
        self::init();
    }

    /**
     * create sms instance and set templates
     * @param null $agentName
     * @param null $tempId
     *
     * @return Sms
     */
    public static function make($agentName = null, $tempId = null)
    {
        $sms = new self;
        if ($agentName) {
            $sms->template($agentName, $tempId);
        }
        return $sms;
    }

    /**
     * send voice verify
     * @param $code
     *
     * @return Sms
     */
    public static function voice($code)
    {
        $sms = new self;
        $sms->smsData['voiceCode'] = $code;
        return $sms;
    }

    /**
     * set how to use queue.
     * @param $enable
     * @param $handler
     */
    public static function queue($enable, $handler = null)
    {
        if (is_callable($enable)) {
            $handler = $enable;
            $enable = true;
        }
        self::$enableQueue = !!$enable;
        if (is_callable($handler)) {
            self::$howToUseQueue = $handler;
        }
    }

    /**
     * set the mobile number
     * @param $mobile
     *
     * @return $this
     */
    public function to($mobile)
    {
        $this->smsData['to'] = $mobile;
        return $this;
    }

    /**
     * set content for content sms
     * @param $content
     *
     * @return $this
     */
    public function content($content)
    {
        $this->smsData['content'] = trim((String) $content);
        return $this;
    }

    /**
     * set template id for template sms
     * @param $agentName
     * @param $tempId
     *
     * @return $this
     */
    public function template($agentName, $tempId = null)
    {
        $tempIdArray = (Array) $this->smsData['templates'];
        if ( ! is_null($tempId)) {
            $tempIdArray["$agentName"] = $tempId;
        } else {
            if (is_array($agentName)) {
                $tempIdArray = $agentName;
            } else {
                $firstAgentName = self::getFirstAgentName();
                $tempIdArray["$firstAgentName"] = $agentName;
            }
        }
        $this->smsData['templates'] = (Array) $tempIdArray;
        return $this;
    }

    /**
     * set data for template sms
     * @param array $data
     *
     * @return $this
     */
    public function data(Array $data)
    {
        $this->smsData['templateData'] = $data;
        return $this;
    }

    /**
     * start send
     * @param  bool  $immediately
     * @return mixed
     */
    public function send($immediately = false)
    {
        $this->validator();
        $results = null;

        // if disable push to queue,
        // send the sms immediately.
        if (!self::$enableQueue) {
            $immediately = true;
        }

        // whatever 'PhpSms' whether to enable or disable push to queue,
        // if you are already pushed sms instance to queue,
        // you can recall the method `send()` in queue job without `true` parameter.
        //
        // So this mechanism in order to make you convenient use the method `send()` in queue system.
        if ($this->pushedToQueue) {
            $immediately = true;
        }

        // whether to send sms immediately,
        // or push it to queue.
        if ($immediately) {
            $results = Balancer::run(self::TASK, $this->getData());
        } else {
            $results = $this->push();
        }
        return $results;
    }

    /**
     * push sms send task to queue
     * @return mixed
     * @throws \Exception | PhpSmsException
     */
    protected function push()
    {
        if (is_callable(self::$howToUseQueue)) {
            try {
                $this->pushedToQueue = true;
                return call_user_func_array(self::$howToUseQueue, [$this, $this->smsData]);
            } catch(\Exception $e) {
                $this->pushedToQueue = false;
                throw $e;
            }
        } else {
            throw new PhpSmsException('Please define how to use queue by method `queue($enable, $handler)`');
        }
    }

    /**
     * get sms data
     * @return array
     */
    public function getData()
    {
        return $this->smsData;
    }

    /**
     * get first agent`s name
     * @return int|null|string
     */
    public static function getFirstAgentName()
    {
        foreach (self::$agentsName as $name => $options) {
            return $name;
        }
    }

    /**
     * init
     */
    protected static function init()
    {
        self::configuration();
        self::generatorTask();
    }

    /**
     * generator a sms send task
     * @return null
     */
    public static function generatorTask()
    {
        if (!Balancer::getTask(self::TASK)) {
            Balancer::task(self::TASK, function($task){
                // create drivers
                self::createAgents($task);
                // set hooks handler
                foreach (self::$hookHandlers as $hook => $handler) {
                    if (is_callable($handler)) {
                        $task->hook($hook, $handler);
                    }
                }
            });
        }
        return Balancer::getTask(self::TASK);
    }

    /**
     * configuration
     */
    protected static function configuration()
    {
        $config = [];
        if (!self::$agentsName) {
            $config = include(__DIR__ . '/../config/phpsms.php');
            self::generatorAgentsName($config);
        }
        if (!self::$agentsConfig) {
            $config = $config ?: include(__DIR__ . '/../config/phpsms.php');
            self::generatorAgentsConfig($config);
        }
        self::configValidator();
    }

    /**
     * generate enabled agents name
     * @param array $config
     */
    protected static function generatorAgentsName($config)
    {
        $config = isset($config['enable']) ? $config['enable'] : null;
        if ($config) {
            self::enable($config);
        }
    }

    /**
     * generator agents config
     * @param array $config
     */
    protected static function generatorAgentsConfig($config)
    {
        $config = isset($config['agents']) ? $config['agents'] : [];
        self::agents($config);
    }

    /**
     * config value validator
     * @throws PhpSmsException
     */
    protected static function configValidator()
    {
        if (!count(self::$agentsName)) {
            throw new PhpSmsException('Please set at least one enable agent in config file(config/phpsms.php) or use method enable()');
        }
        foreach (self::$agentsName as $agentName => $options) {
            if ($agentName == self::LOG_AGENT) {
                continue;
            }
            if (!isset(self::$agentsConfig[$agentName])) {
                throw new PhpSmsException("Please configuration [$agentName] agent in config file(config/phpsms.php) or use method agents()");
            }
        }
    }

    /**
     * create drivers for sms send task
     * @param $task
     */
    protected static function createAgents($task)
    {
        foreach (self::$agentsName as $name => $options) {
            $configData = self::getAgentConfigData($name);
            $task->driver("$name $options")
                 ->data($configData)
                 ->work(function($driver, $data){
                     $configData = $driver->getDriverData();
                     $agent = self::getSmsAgent($driver->name, $configData);
                     $smsData = $driver->getTaskData();
                     extract($smsData);
                     if (isset($smsData['voiceCode']) && $smsData['voiceCode']) {
                         $agent->voiceVerify($to, $voiceCode);
                     } else {
                         $template = isset($templates[$driver->name]) ? $templates[$driver->name] : 0;
                         $agent->sendSms($template, $to, $templateData, $content);
                     }
                     $result = $agent->getResult();
                     if ($result['success']) {
                         $driver->success();
                     }
                     unset($result['success']);
                     return $result;
                 });
        }
    }

    /**
     * get agent config data by name
     * @param $name
     *
     * @return array
     */
    protected static function getAgentConfigData($name)
    {
        return isset(self::$agentsConfig[$name]) ?
               (Array) self::$agentsConfig[$name] : [];
    }

    /**
     * get a sms agent instance,
     * if null, will create a new agent instance
     * @param       $name
     * @param array $configData
     * @throws PhpSmsException
     * @return mixed
     */
    protected static function getSmsAgent($name, Array $configData)
    {
        if (!isset(self::$agents[$name])) {
            $className = 'Toplan\\PhpSms\\' . $name . 'Agent';
            if (class_exists($className)) {
                self::$agents[$name] = new $className($configData);
            } else {
                throw new PhpSmsException("Agent [$name] not support.");
            }
        }
        return self::$agents[$name];
    }

    /**
     * validate
     * @throws PhpSmsException
     */
    protected function validator()
    {
        if (!$this->smsData['to']) {
            throw new PhpSmsException("Please set send sms(or voice verify) to who use `to()` method.");
        }
        return true;
    }

    /**
     * set enable agents
     * @param      $agentName
     * @param null $options
     */
    public static function enable($agentName, $options = null)
    {
        if (is_array($agentName)) {
            //([
            //  'name1' => 'opt',
            //  'name2',
            //  ......
            //])
            foreach ($agentName as $name => $opt) {
                self::enable($name, $opt);
            }
        } elseif ($agentName && is_string($agentName) && !is_array($options) && is_string("$options")) {
            //(name, opts)
            self::$agentsName["$agentName"] = "$options";
        } elseif (is_integer($agentName) && !is_array($options) && "$options") {
            //(0, name)
            //(1, name)
            self::$agentsName["$options"] = '1';
        } elseif ($agentName && $options === null) {
            //(name)
            self::$agentsName["$agentName"] = '1';
        }
    }

    /**
     * set config for available agents
     * @param       $agentName
     * @param array $config
     *
     * @throws PhpSmsException
     */
    public static function agents($agentName, Array $config = [])
    {
        if (is_array($agentName)) {
            foreach ($agentName as $name => $conf) {
                self::agents($name, $conf);
            }
        } elseif ($agentName && is_array($config)){
            if (preg_match('/^[0-9]+$/', $agentName)) {
                throw new PhpSmsException("Agent name [$agentName] must be string, could not be a pure digital");
            }
            self::$agentsConfig["$agentName"] = $config;
        }
    }

    /**
     * get enable agents
     * @return array
     */
    public static function getEnableAgents()
    {
        return self::$agentsName;
    }

    /**
     * get agents config info
     * @return array
     */
    public static function getAgentsConfig()
    {
        return self::$agentsConfig;
    }

    /**
     * overload static method
     * @param $name
     * @param $args
     *
     * @throws PhpSmsException
     */
    public static function __callStatic($name, $args) {
        $name = $name == 'beforeSend' ? 'beforeRun' : $name;
        $name = $name == 'afterSend' ? 'afterRun' : $name;
        if (array_key_exists($name, self::$hookHandlers)) {
            $handler = $args[0];
            if ($handler && is_callable($handler)) {
                self::$hookHandlers[$name] = $handler;
            } else {
                throw new PhpSmsException("Please give static method $name() a callable parameter");
            }
        } else {
            throw new PhpSmsException("Do not find static method $name()");
        }
    }
}
