<?php

namespace aleksip\DataTransformPlugin;

use Drupal\Core\Template\Attribute;
use Drupal\Core\Url;
use PatternLab\Data;
use PatternLab\PatternData;

class PatternNotFoundException extends \Exception {}

class DataTransformer
{
    protected static $processed = array();

    protected $reservedKeys;
    protected $patternDataStore;
    protected $env;
    protected $hasRun;

    public function __construct()
    {
        // TODO: Add an accessor function for $reservedKeys to the Data class?
        $this->reservedKeys = array("cacheBuster","link","patternSpecific","patternLabHead","patternLabFoot");
        $this->patternDataStore = PatternData::get();
    }

    public function run(\Twig_Environment $env)
    {
        $this->env = $env;
        if ($this->hasRun) {
            return;
        }
        // Process global data.
        $dataStore = $this->processData(Data::get());
        Data::replaceStore($dataStore);
        // Process pattern specific data.
        foreach (array_keys($this->patternDataStore) as $pattern) {
            $this->processPattern($pattern);
        }
        $this->hasRun = true;
    }

    protected function isProcessed($pattern)
    {
        return isset(self::$processed[$pattern]);
    }

    protected function setProcessed($pattern)
    {
        self::$processed[$pattern] = true;
    }

    protected function processPattern($pattern)
    {
        if (
            $this->isProcessed($pattern)
            || !isset($this->patternDataStore[$pattern])
            || $this->patternDataStore[$pattern]['category'] != 'pattern'
        ) {
            return;
        }
        $this->setProcessed($pattern);
        try {
            $patternSpecificData =
              $this->processData(Data::getPatternSpecificData($pattern));


            $dataStore = Data::get();
            foreach (array_keys($patternSpecificData) as $key) {
              if (!isset($dataStore['patternSpecific'][$pattern]['data'][$key])) {
                // Value is default global data.
                if (is_object($dataStore[$key])) {
                  $patternSpecificData[$key] = clone $dataStore[$key];
                }
              }
            }
            Data::initPattern($pattern);
            Data::setPatternData($pattern, $patternSpecificData);
        }
        catch (PatternNotFoundException $exception) {
            throw new PatternNotFoundException("Pattern '$pattern': " . $exception->getMessage());
        }
    }

    protected function processData($data)
    {
        foreach (array_keys($data) as $key) {
            if (!in_array($key, $this->reservedKeys)) {
                $data = $this->processKey($data, $key);
            }
        }

        return $data;
    }

    protected function processKey($data, $key)
    {
        $value = $data[$key];
        if (is_array($value)) {
            foreach (array_keys($value) as $subKey) {
                $value = $this->processKey($value, $subKey);
            }
            // attribute values nested below Attribute()
            if (isset($value['Attribute()']) && is_array($value['Attribute()'])) {
                $data[$key] = new Attribute($value['Attribute()']);
            }
            // attribute values set alongside Attribute()
            elseif (isset($value['Attribute()']) && is_bool($value['Attribute()'])) {
              unset($value['Attribute()']);
              $data[$key] = new Attribute($value);
            }
            // url values nested below Url()
            elseif (isset($value['Url()']['url'])) {
              $options = isset($value['Url()']['options']) && is_array($value['Url()']['options']) ? $value['Url()']['options'] : [];
              $data[$key] = Url::fromUri($value['Url()']['url'], $options);
            }
            // url values set alongside Url()
            elseif (isset($value['Url()']) && is_string($value['Url()']) && !empty($value['Url()'])) {
              $options = isset($value['options']) && is_array($value['options']) ? $value['options'] : [];
              $data[$key] = Url::fromUri($value['Url()'], $options);
            }
            elseif (isset($value['include()']) && is_array($value['include()']) && isset($value['include()']['pattern'])) {
                $pattern = $value['include()']['pattern'];
                // variables alongside include()
                if (!isset($value['include()']['with']) || !is_array($value['include()']['with'])) {
                    $with = $value;
                    unset($with['include()']);
                }
                // variables nested under 'with'
                else {
                    $with = $value['include()']['with'];
                }
                if (is_string($pattern) && isset($this->patternDataStore[$pattern])) {
                    if (!isset($value['include()']['only'])) {
                        $patternData = $this->getProcessedPatternSpecificData($pattern, $with);
                    }
                    else {
                        $patternData = $with;
                    }
                    $data[$key] = $this->renderPattern($pattern, $patternData);
                }
                else {
                    throw new PatternNotFoundException("Could not find pattern '$pattern' to include!");
                }
            }
            elseif (isset($value['join()']) && is_array($value['join()'])) {
                $data[$key] = join($value['join()']);
            }
            elseif (isset($value['join()']) && is_bool($value['join()'])) {
              unset($value['join()']);
              $data[$key] = join($value);
            }
            else {
                $data[$key] = $value;
            }
        }
        elseif (is_string($value) && isset($this->patternDataStore[$value]) && $key !== 'pattern') {
            $data[$key] = $this->renderPattern($value, $this->getProcessedPatternSpecificData($value));
        }

        return $data;
    }

    public function getProcessedPatternSpecificData($pattern, $extraData = array())
    {
        $this->processPattern($pattern);

        return Data::getPatternSpecificData($pattern, $extraData);
    }

    protected function renderPattern($pattern, $data)
    {
        if (isset($this->patternDataStore[$pattern]['patternRaw'])) {
            foreach (array_keys($data) as $key) {
                $data = $this->cloneObjects($data, $key);
            }
            $pattern = $this->env->render(
                $this->patternDataStore[$pattern]['patternRaw'],
                $data
            );
        }

        return $pattern;
    }

    protected function cloneObjects($data, $key)
    {
        $value = $data[$key];
        if (is_array($value)) {
            foreach (array_keys($value) as $subKey) {
                $value = $this->cloneObjects($value, $subKey);
            }
            $data[$key] = $value;
        }
        elseif (is_object($value)) {
            $data[$key] = clone $value;
        }

        return $data;
    }
}
