<?php

namespace yiidreamteam\perfectmoney\actions;

use Yii;
use yii\base\Action;
use yii\base\InvalidConfigException;

use yiidreamteam\perfectmoney\Api;

/**
 * @author Alexey Samoylov <alexey.samoylov@gmail.com>
 */
class ResultAction extends Action
{
    /** @var string */
    public $componentName;

    /** @var string */
    public $redirectUrl;

    /** @var bool */
    public $silent = false;

    /** @var Api */
    private $api;

    /**
     * @throws \yii\base\InvalidConfigException
     */
    public function init()
    {
        $this->api = Yii::$app->get($this->componentName);

        if (!$this->api instanceof Api) {
            throw new InvalidConfigException('Invalid PerfectMoney component configuration');
        }

        parent::init();
    }

    public function run()
    {
        try {
            $this->api->processResult(Yii::$app->request->post());
        } catch (\Exception $e) {
            if (!$this->silent) {
                throw $e;
            }
        }

        if ($this->redirectUrl !== null) {
            return Yii::$app->response->redirect($this->redirectUrl);
        }
    }
}
