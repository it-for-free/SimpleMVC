<?php
namespace ItForFree\SimpleMVC;

use ItForFree\rusphp\PHP\ArrayLib\DotNotation\Dot;
use ItForFree\SimpleMVC\ExceptionHandler;
use ItForFree\SimpleMVC\Exceptions\SmvcUsageException;
use ItForFree\SimpleMVC\Exceptions\SmvcConfigException;
use ItForFree\rusphp\PHP\Object\ObjectFactory;
use ItForFree\rusphp\PHP\ArrayLib\Structure;
use ItForFree\SimpleMVC\Exceptions\SmvcCoreException;

/**
 * Класс-"точка входа" для работы фреймворка SimpleMVC
 * 
 * Реализует паттерн "Одиночка" @see http://fkn.ktu10.com/?q=node/5572
 */
class Application
{
    /**
     * Массив конфигурации приложения
     * 
     * @var ItForFree\rusphp\PHP\ArrayLib\DotNotation\Dot
     */
    protected ?Dot $config = null;

    /**
     * Кэш контейнера (конфигурируемых компонентов приложения)
     */
    protected array $containerElements = [
		'elements' => [],
		'objects' => [],
	];
        
    /**
     * Cкрываем конструктор для того чтобы класс нельзя было создать в обход get() 
     */
    protected function __construct() {}
    
    /**
     * Метод для получения текущего объекта приложения
     * 
     * @staticvar Application $instance
     */
    public static function get(): Application
    {
        static $instance = null; // статическая переменная
        if (null === $instance) { // проверка существования
            $instance = new static();
        }
        return $instance;
    }
    
    public static function addElementToConteiner(string $configPath, mixed $element): void
    {
        
        self::get()->containerElements['elements'][$configPath] = $element;
    }
	
	public static function addObjectToConteiner(string $configPath, object $object): void
    {
        self::get()->containerElements['objects'][$configPath] = $object;
    }
 
    /**
     * Запускает функционал ядра, 
     * именно этот метод должно вызвать приложение, использущее SimpleMVC 
     * для запуска системы
     * 
     * @throws SmvcCoreException
     */
    public function run(): Application {
        
        $exceptionHandler = new ExceptionHandler();
        try{
            if (!empty($this->config)) {
                $route = $this->getConfigObject('core.router.class')::getRoute();
                /**
		 * @var \ItForFree\SimpleMVC\Router\WebRouter
		 */
                $Router = $this->getConfigObject('core.router.class');
                $Router->callControllerAction($route); // определяем и вызываем нужно действие контроллера
            } else {
                throw new SmvcCoreException('Не задан конфигурационный массив приложения!');
            }               
        } catch (\Exception $exc) {
            $exceptionHandler->handleException($exc);
        }
	return $this;
    }
    
    /**
     * Устанавливает конфигурацию приложения из массива
     * 
     * @param  array $config многомерный массив конфигурации приложения
     */
    public function setConfiguration(array $config, bool $resetContainerCache = true): object
    {
        $this->config = new Dot($config);
        if ($resetContainerCache) {
            $this->containerElements = [
                'elements' => [],
                'objects' => [],
            ];
        }
        return $this;
    }
    
    /**
     * Вернёт элемент из массива конфигурации приложения
     * 
     * @param string $inConfigArrayPath ключ в виде строки, разделёной точками -- путь в массиве
     * $withException - флаг, кторый определяет омежт ли бросать исключения метод или нет
     */
    public static function getConfigElement(string $inConfigArrayPath, bool $withException = true): mixed
    {
		if (empty(self::get()->config)) {
            throw new SmvcUsageException('Не задан конфигурационный массив приложения!');
        }
        
        if (isset(self::get()->containerElements['elements'][$inConfigArrayPath])) {
			return self::get()->containerElements['elements'][$inConfigArrayPath];
		} else {
        
			$configValue = self::get()->config->get($inConfigArrayPath);

			if ($withException && is_null($configValue)) {
			   throw new SmvcConfigException("Элемент с данным путём [$inConfigArrayPath]"
					   . " отсутствует в конфигурационном массиве приложения!");
			}
			self::addElementToConteiner($inConfigArrayPath, $configValue);
			return $configValue;
		}
    }
    
    /**
     * Создаст и вернёт объект по его имени из массива
     * 
     * @param string $inConfigArrayPath ключ в виде строки, разделёной точками -- путь в массиве
     * $a[] = $param;
     */
    public static function getConfigObject(string $inConfigArrayPath): mixed
    {
        $publicParams = array();
        $constructParams = array();
        $fullClassName = self::getConfigElement($inConfigArrayPath);

        $currentConteiner = self::get()->containerElements;
        if (isset(self::get()->containerElements['objects'][$inConfigArrayPath])) 
		{ 
		    return self::get()->containerElements['objects'][$inConfigArrayPath];
		} else {
            if (!class_exists($fullClassName)) {
                throw new SmvcConfigException("Вы запросили получение экземпляра класса "
                    . "$fullClassName "
                    . " (т.к. он был добавлен в конфиг по адресу $fullClassName),"
                    . " но такой класс не был ранее объявляен, "
                    . "убедитесь в том, что его код подключен "
                    . "до обращения к экземпляру объекта. ");
            }

            $constructParams = self::getCounstractParams($inConfigArrayPath);
            $publicParams = self::getPablicParams($inConfigArrayPath);

            $newObject = static::getInstanceOrSingletone($fullClassName, $constructParams, $publicParams);
            self::addObjectToConteiner($inConfigArrayPath, $newObject);
            return $newObject;
        }
    }
    
    
    protected static function getInstanceOrSingletone(
		string $className, 
		array $constructParams = [],
		array $publicParams = [], 
		string $singletoneInstanceAccessStaticMethodName = 'get'): object
    { 
       $result = null;
       if (\ItForFree\rusphp\PHP\Object\ObjectClass\Constructor::isPublic($className)) {

          if (!empty($constructParams))
          {
             $result = ObjectFactory::createObjectByConstruct($className, $constructParams);
          } else {
               $result = new $className;
          }
	  
	  if (!empty($publicParams)) {
            ObjectFactory::setPublicParams($result, $publicParams);
          } 
	  
       } else {
            $result =  call_user_func($className . '::' 
                . $singletoneInstanceAccessStaticMethodName); 
       }
       
       self::addObjectToConteiner($className, $result);
       return $result;
    }
    
    protected static function getPathParams(string $PathClassName, string $additionPart): string 
    {
        
        $pathParams = explode('.', $PathClassName);
        return $pathParams[0] . '.' . $pathParams[1] . '.' . $additionPart;
    }

    protected static function isAlias(string $param): bool 
    {
	return strpos($param, '@') === 0;	
    }

    protected static function getPablicParams(string $inConfigArrayPath): array 
    {
        $publicParams = array();
        $paramsPath = static::getPathParams($inConfigArrayPath, 'params');
        
        if ($paramsPath) { 
            $params = self::getConfigElement($paramsPath, false);
        }

        if (!empty($params)) {
            foreach($params as $param) {
                if (static::isAlias($param)) {
                        $publicParams[substr($param, 1)] = self::getInstanceByAlias($param);
                }
            }
        }
        return $publicParams;                    
    }
    
    protected static function getCounstractParams(string $inConfigArrayPath): array
    {
        $readyCounstractParams = array();
        $pathConstructParams = static::getPathParams($inConfigArrayPath, 'construct');
        if ($pathConstructParams)  {
            $constructParams = self::getConfigElement($pathConstructParams, false);
        }
                            
        if (!empty($constructParams)) 
        {
            foreach($constructParams as $param) {
                if (static::isAlias($param)) {
                        $readyCounstractParams[substr($param, 1)] = self::getInstanceByAlias($param);
                }
            }
        }   
        return $readyCounstractParams; 
    }
    
    
    /*
     * Возвращает объект или элемент, созданный
     * на основе переданного параметр
     */
    public static function getInstanceByAlias(string $param): mixed
    {
        //возвращает объект или элемент взависимости от того, есть ли в конфиге у переданного пути часть "класс".
        //Вызывает isClassOrSimpleElement как раз для этой проверки
        $pathToTheDesiredElement = Structure::getPathForElementWithValue(self::get()->config, 'alias', $param);
        if(self::isClassOrSimpleElement($pathToTheDesiredElement)) {
            return self::getConfigObject(implode('.', $pathToTheDesiredElement) . '.class');
        } else {
            return self::getConfigElement(implode('.', $pathToTheDesiredElement));
        }
        
            
    }
    
    /*
     * Проверяет: можно ли создать объект класса по переданному пути или нет
     * Возвращает true/false
     */
    public static function isClassOrSimpleElement(array $paramPath): bool
    {       
        $pathToClass = implode('.', $paramPath) . '.class';
        $class = self::getConfigElement($pathToClass, false);
        return !empty($class);
    }
}
