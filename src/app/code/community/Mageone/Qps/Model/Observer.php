<?php

/**
 * Class Mageone_Qps_Model_Observer
 */
class Mageone_Qps_Model_Observer
{
    const QPS_CACHE = 'qps_security';
    const QPS_CACHE_TAG = 'qps';
    const QPS_LOG = 'qps.log';

    private $rules = [];

    public function checkRequest(Varien_Event_Observer $observer)
    {
        if (!Mage::helper('qps')->isEnabled()) {
            return null;
        }
        $checkArray = [$_SERVER, $_COOKIE, $_REQUEST, $_FILES, $_POST, $_GET, $_ENV];
        if (session_status() === PHP_SESSION_ACTIVE) {
            $checkArray[] = $_SESSION;
        }

        if ($this->getRules() && is_array($this->getRules())) {
            foreach ($checkArray as $data) {
                $this->checkGlobalArrayData($data);
            }
        }

        return $this;
    }

    /**
     * @return array
     */
    private function getRules()
    {
        if (empty($this->rules)) {
            $this->rules = $this->getCachedData();
        }

        return $this->rules;
    }

    /**
     * @return array
     */
    private function getCachedData()
    {
        if (Mage::helper('pagecache')->isEnabled()) {
            if (Mage::app()->loadCache(self::QPS_CACHE) === false) {
                $data = $this->getCollection();
                Mage::app()->saveCache(Mage::helper('core')->jsonEncode($data), self::QPS_CACHE, [self::QPS_CACHE_TAG]);
            } else {
                $data = Mage::helper('core')->jsonDecode(Mage::app()->loadCache(self::QPS_CACHE));
            }
        } else {
            $data = $this->getCollection();
        }

        return $data;
    }

    /**
     * @return mixed
     */
    private function getCollection()
    {
        return Mage::getResourceModel('qps/rule_collection')->getData();
    }

    private function checkGlobalArrayData($data)
    {
        try {
            foreach ($this->getRules() as $rule) {
                try {
                    $this->validateRule($rule, $request);
                } catch (Mageone_Qps_Model_Exception_RuleNotPassedException $e) {
                    $this->processTriggeredRule();
                }
            }
        } catch (Exception $e) {
            Mage::logException($e);
        }
    }

    private function checkRule($rule, $key, $value)
    {
        if (isset($rule['target'])) {
            $parts = explode(',', $rule['target']);
            if (!is_array($parts)) {
                $parts = [$parts];
            }
            $valid = true;
            foreach ($parts as $part) {
                $value   = $this->getValue($part, $rule);
                $matches = [];
                if ($rule['type'] === 'regex') {
                    preg_match_all($rule['rule_content'], $value, $matches);
                    if (isset($matches[0]) && count($matches[0])) {
                        Mage::log('Bad request : ' . $value, Zend_Log::ALERT, self::QPS_LOG);
                        $valid = false;
                    }
                } else {
                    Mage::dispatchEvent(
                        'qps_custom_check',
                        ['rule' => $rule, 'key' => $key, 'value' => $value, 'valid' => $valid]
                    );
                }

                return $valid;
            }
        }
    }

    /**
     * @param string $part
     * @param        $rule
     *
     * @return bool|false|mixed|string
     */
    private function getValue($part, $rule)
    {
        $value = $this->getValueFromGlobal($part);

        if ($value === false) {
            return false;
        }

        switch ($rule) {
            case 'base64_decode':
                return base64_decode($value);
                break;
            case 'json_decode':
                return Mage::helper('core')->jsonDecode($value);
                break;
            case 'rawurldecode':
            default:
                return $value;
        }

    }

    private function getValueFromGlobal($key)
    {
        $start = mb_strpos($key, '[');
        $end   = mb_strpos($key, ']');
        if ($start !== false && $end !== false) {
            $global    = mb_substr($key, 0, $start);
            $globalKey = mb_substr($key, $start + 2, $end - $start - 3);
            if (isset($GLOBALS[$global][$globalKey]) && !empty($global) && !empty($globalKey)) {
                return $GLOBALS[$global][$globalKey];
            }
        }

        return false;
    }

    private function processTriggeredRule()
    {
        if (defined('TESTING')) {
            throw new Mageone_Qps_Model_Exception_ExitSkippedForTestingException(
                'Rule did not pass.'
            );
        }
        Mage::log('Bad request from: ' . Mage::app()->getRequest()->getClientIp(true), Zend_Log::ALERT, self::QPS_LOG);
        Mage::app()->getResponse()->setHttpResponseCode(503)->sendHeaders();
        exit;
    }
}
