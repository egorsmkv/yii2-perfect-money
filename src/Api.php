<?php

namespace yiidreamteam\perfectmoney;

use Yii;
use yii\base\Component;
use yii\helpers\ArrayHelper;
use yii\helpers\Url;
use yii\helpers\VarDumper;
use yii\web\ForbiddenHttpException;
use yii\web\HttpException;

use yii\httpclient\Response;
use yii\httpclient\Client;

use yiidreamteam\perfectmoney\events\GatewayEvent;

/**
 * @author Alexey Samoylov <alexey.samoylov@gmail.com>
 */
class Api extends Component
{
    /** @var string Account ID */
    public $accountId;

    /** @var string Account password */
    public $accountPassword;

    /** @var string Wallet number (e.g. U123456) */
    public $walletNumber;

    /** @var string Wallet currency (e.g. USD) */
    public $walletCurrency = 'USD';

    /** @var string Secret string from the PM settings page */
    public $alternateSecret;

    /** @var string Merchant name to display in payment form */
    public $merchantName;

    protected $hash;

    public $resultUrl;
    public $successUrl;
    public $failureUrl;

    public function init()
    {
        assert($this->accountId !== null);
        assert($this->accountPassword !== null);
        assert($this->walletNumber !== null);
        assert($this->alternateSecret !== null);
        assert($this->merchantName !== null);

        $this->resultUrl = Url::to($this->resultUrl, true);
        $this->successUrl = Url::to($this->successUrl, true);
        $this->failureUrl = Url::to($this->failureUrl, true);

        $this->hash = strtoupper(md5($this->alternateSecret));
    }

    /**
     * Transfers money to another account
     *
     * @param string $target Target wallet
     * @param float $amount
     * @param string|null $paymentId
     * @param string|null $memo
     *
     * @return array|false
     */
    public function transfer($target, $amount, $paymentId = null, $memo = null)
    {
        $params = [
            'Payer_Account' => $this->walletNumber,
            'Payee_Account' => $target,
            'Amount' => $amount,
            'PAY_IN' => 1,
        ];

        if (mb_strlen($paymentId) > 0) {
            $params['PAYMENT_ID'] = $paymentId;
        }

        if (mb_strlen($memo) > 0) {
            $params['Memo'] = $memo;
        }

        return $this->call('confirm', $params);
    }

    /**
     * Performs api call
     *
     * @param string $script Api script name
     * @param array $params Request parameters
     *
     * @return array|bool
     */
    public function call($script, array $params = [])
    {
        $defaults = [
            'AccountID' => $this->accountId,
            'PassPhrase' => $this->accountPassword,
        ];

        $params = ArrayHelper::merge($defaults, $params);

        $client = new Client();
        /** @var Response $response */
        $response = $client->createRequest()
            ->setMethod('post')
            ->setUrl(sprintf('https://perfectmoney.is/acct/%s.asp', $script))
            ->setData($params)
            ->send();

        if ($response->isOk) {
            $content = $response->getContent();

            $items = [];
            if (!preg_match_all("/<input name='(.*)' type='hidden' value='(.*)'>/", $content, $items, PREG_SET_ORDER)) {
                return false;
            }

            $result = [];
            foreach ($items as $item) {
                if (count($item) !== 3) {
                    continue;
                }

                $result[$item[1]] = $item[2];
            }

            return $result;
        }

        Yii::error('API call returned status code: ' . $response->statusCode, 'PerfectMoney');

        return false;
    }

    /**
     * Get account wallet balance
     *
     * @return array|bool
     */
    public function balance()
    {
        return $this->call('balance');
    }

    /**
     * @param array $data
     *
     * @return bool
     *
     * @throws HttpException
     * @throws \yii\web\ForbiddenHttpException
     * @throws \yii\db\Exception
     */
    public function processResult($data)
    {
        if (!$this->checkHash($data)) {
            throw new ForbiddenHttpException('Hash error');
        }

        $event = new GatewayEvent(['gatewayData' => $data]);

        $this->trigger(GatewayEvent::EVENT_PAYMENT_REQUEST, $event);
        if (!$event->handled) {
            throw new HttpException(503, 'Error processing request');
        }

        $transaction = Yii::$app->getDb()->beginTransaction();
        try {
            $this->trigger(GatewayEvent::EVENT_PAYMENT_SUCCESS, $event);
            $transaction->commit();
        } catch (\Exception $e) {
            $transaction->rollBack();
            Yii::error('Payment processing error: ' . $e->getMessage(), 'PerfectMoney');
            throw new HttpException(503, 'Error processing request');
        }

        return true;
    }

    /**
     * Return result of checking SCI hash
     *
     * @param array $data Request array to check, usually $_POST
     *
     * @return bool
     */
    public function checkHash($data)
    {
        if (!isset($data['PAYMENT_ID'],
            $data['PAYEE_ACCOUNT'],
            $data['PAYMENT_AMOUNT'],
            $data['PAYMENT_UNITS'],
            $data['PAYMENT_BATCH_NUM'],
            $data['PAYER_ACCOUNT'],
            $data['TIMESTAMPGMT'],
            $data['V2_HASH'])
        ) {
            return false;
        }

        $params = [
            $data['PAYMENT_ID'],
            $data['PAYEE_ACCOUNT'],
            $data['PAYMENT_AMOUNT'],
            $data['PAYMENT_UNITS'],
            $data['PAYMENT_BATCH_NUM'],
            $data['PAYER_ACCOUNT'],
            $this->hash,
            $data['TIMESTAMPGMT'],
        ];

        $hash = strtoupper(md5(implode(':', $params)));

        if ($hash === $data['V2_HASH']) {
            return true;
        }

        Yii::error('Hash check failed: ' . VarDumper::dumpAsString($params), 'PerfectMoney');

        return false;
    }
}
