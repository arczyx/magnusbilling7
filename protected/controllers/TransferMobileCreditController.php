<?php
/**
 * =======================================
 * ###################################
 * MagnusBilling
 *
 * @package MagnusBilling
 * @author Adilson Leffa Magnus.
 * @copyright Copyright (C) 2005 - 2021 MagnusBilling. All rights reserved.
 * ###################################
 *
 * This software is released under the terms of the GNU Lesser General Public License v2.1
 * A copy of which is available from http://www.gnu.org/copyleft/lesser.html
 *
 * Please submit bug reports, patches, etc to https://github.com/magnusbilling/mbilling/issues
 * =======================================
 * Magnusbilling.com <info@magnusbilling.com>
 *
 */

class TransferMobileCreditController extends Controller
{
    private $url;
    private $amounts    = 0;
    private $user_cost  = 0;
    private $agent_cost = 0;
    private $login;
    private $token;
    private $currency;
    private $send_credit_id;
    private $user_profit;
    private $test = false;
    private $cost;
    private $showprice;
    private $sell_price;
    private $local_currency;
    public $modelTransferToMobile = array();
    public $operator_name;

    private $number;

    public function init()
    {

        $this->modelTransferToMobile = TransferToMobile::model()->findByPk((int) Yii::app()->session['id_user']);

        $this->instanceModel = new User;
        $this->abstractModel = User::model();
        parent::init();

        $this->login    = $this->config['global']['fm_transfer_to_username'];
        $this->token    = $this->config['global']['fm_transfer_to_ token'];
        $this->currency = $this->config['global']['fm_transfer_currency'];
        $this->url      = 'https://fm.transfer-to.com/cgi-bin/shop/topup?';

    }

    public function actionIndex($asJson = true, $condition = null)
    {

        if (!isset($_POST['TransferToMobile']['number'])) {
            $this->modelTransferToMobile->method = "Mobile Credit";
            $this->render('insertNumber', array(
                'modelTransferToMobile' => $this->modelTransferToMobile,

            ));
            return;
        }

        $this->number = $this->modelTransferToMobile->number = (int) $_POST['TransferToMobile']['number'];

        if (isset($this->number) && $this->number == '55519824647312') {
            $this->test = true;
        }

        if (isset($this->number) && substr($this->number, 0, 2) == '00') {
            $this->number = substr($this->number, 2);
        }

        if (isset($_POST['amountValues'])) {
            $_POST['TransferToMobile']['amountValues'] = $_POST['amountValues'];
        }

        //if we already request the number info, check if select a valid amount
        if (isset($_POST['TransferToMobile']['amountValues']) || isset($_POST['TransferToMobile']['amountValuesEUR'])) {

            $this->number = $this->number;

            $this->modelTransferToMobile->country  = $_POST['TransferToMobile']['country'];
            $this->modelTransferToMobile->operator = $_POST['TransferToMobile']['operator'];

            if (isset($_POST['TransferToMobile']['amountValuesBDT']) && $_POST['TransferToMobile']['amountValuesBDT'] > 0) {
                $this->modelTransferToMobile->amountValues = Yii::app()->session['interval_product_id'];
                $_POST['TransferToMobile']['amountValues'] = Yii::app()->session['interval_product_id'];
            } else {
                $this->modelTransferToMobile->amountValues = $_POST['TransferToMobile']['amountValues'];
            }

            if (!is_numeric($_POST['TransferToMobile']['amountValues'])) {
                $this->modelTransferToMobile->addError('amountValues', Yii::t('zii', 'Invalid amount'));
            } elseif (!count($this->modelTransferToMobile->getErrors())) {

                if (!isset($_POST['TransferToMobile']['confirmed'])) {

                    if (isset($_POST['amountValues'])) {
                        $_POST['TransferToMobile']['amountValues'] = $_POST['amountValues'];
                    }

                    $modelSendCreditRates = SendCreditRates::model()->find(array(
                        'condition' => 'id_user = :key',
                        'params'    => array(
                            ':key'  => Yii::app()->session['id_user'],
                            ':key1' => $_POST['TransferToMobile']['amountValues'],
                        ),
                        'with'      => array(
                            'idProduct' => array(
                                'condition' => 'idProduct.id =  :key1',
                            ),
                        ),
                    ));

                    if (preg_match_all('/-/', $modelSendCreditRates->idProduct->product) && isset($_POST['TransferToMobile']['amountValuesEUR']) && $_POST['TransferToMobile']['amountValuesEUR'] > 0) {
                        $modelSendCreditRates->sell_price = $_POST['TransferToMobile']['amountValuesEUR'];
                    }

                    $this->render('confirm', array(
                        'modelTransferToMobile' => $this->modelTransferToMobile,
                        'modelSendCreditRates'  => $modelSendCreditRates,
                        'post'                  => $_POST,
                    ));

                    exit;
                } else {

                    $this->confirmRefill();
                }

            }

        }

        if ($this->number == '' || !is_numeric($this->number)
            || strlen($this->number) < 8
            || preg_match('/ /', $this->number)) {
            $this->modelTransferToMobile->addError('number', Yii::t('zii', 'Number invalid, try again'));
        }

        //request number information
        if (!count($this->modelTransferToMobile->getErrors())) {
            if ($this->actionMsisdn_info() === false) {
                echo 'Service inactive';
                exit;
            }
        }

        $amountDetails = null;

        $this->render('index', array(
            'modelTransferToMobile' => $this->modelTransferToMobile,

        ));

    }

    public function addInDataBase()
    {
        $modelSendCreditSummary            = new SendCreditSummary();
        $modelSendCreditSummary->id_user   = Yii::app()->session['id_user'];
        $modelSendCreditSummary->service   = 'Mobile Credit';
        $modelSendCreditSummary->number    = $this->number;
        $modelSendCreditSummary->confirmed = 1;
        $modelSendCreditSummary->cost      = $this->user_cost;
        $modelSendCreditSummary->save();
        $this->send_credit_id = $modelSendCreditSummary->id;
    }

    public function updateDataBase()
    {

        if ($this->sell_price > 0 && $this->user_cost > 0) {

            $profit = 'transfer_international_profit';
            SendCreditSummary::model()->updateByPk($this->send_credit_id, array(
                'profit' => $this->modelTransferToMobile->{$profit},
                'amount' => $this->cost,
                'sell'   => number_format($this->sell_price, 2),
                'earned' => number_format($this->sell_price - $this->user_cost, 2),
            ));
        } else {
            SendCreditSummary::model()->deleteByPk($this->send_credit_id);
        }
    }

    public function sendActionTransferToMobile($action, $product = null)
    {

        $number = $this->number;
        $key    = time();
        $md5    = md5($this->login . $this->token . $key);

        if ($action == 'topup') {
            $modelSendCreditProducts = SendCreditProducts::model()->find(array(
                'condition' => 'operator_name = :key AND product = :key1',
                'params'    => array(
                    ':key'  => $this->modelTransferToMobile->operator,
                    ':key1' => $product,
                ),
            ));
            $this->url = "https://airtime.transferto.com/cgi-bin/shop/topup?";
            $action .= '&msisdn=number&delivered_amount_info=1&product=' . $product . '&operatorid=' . $modelSendCreditProducts->operator_id . '&sms_sent=yes';
        }

        $url = $this->url . "login=" . $this->login . "&key=$key&md5=$md5&destination_msisdn=$number&action=" . $action;

        $arrContextOptions = array(
            "ssl" => array(
                "verify_peer"      => false,
                "verify_peer_name" => false,
            ),
        );

        if ($this->test == true && preg_match('/topup/', $action)) {
            return $result = 'transactionid=662680426
msisdn=number
destination_msisdn=5551982464731
country=Brazil
countryid=691
operator=Vivo Brazil
operatorid=708
reference_operator=55674
originating_currency=EUR
destination_currency=BRL
product_requested=7
actual_product_sent=7
wholesale_price=2.40
retail_price=2.40
balance=1320.90
sms_sent=yes
sms=
cid1=
cid2=
cid3=
local_info_value=7
local_info_amount=7
local_info_currency=BRL
authentication_key=1529417078
error_code=0
error_txt=Transaction successful';
        } else {

            if (!$result = @file_get_contents($url, false, stream_context_create($arrContextOptions))) {
                $result = '';
            }
        }

        if ($this->test == true) {
            //echo $url . "<br>";
        }

        return $result;
    }

    public function calculateCost($product = 0, $post)
    {

        $methosProfit = 'transfer_international_profit';

        if ($this->test == true) {
            echo "<br>" . 'cost=' . $this->cost . ' - prodict=' . $product . "<br>";
        }

        if ($this->modelTransferToMobile->credit + $this->modelTransferToMobile->creditlimit < $this->cost) {

            echo '<div id="container"><div id="form"> <div id="box-4"><div class="control-group">';
            echo '<form action="" id="form1" method="POST">';
            echo '<font color=red>ERROR:You no have enough credit to transfer</font>';
            echo '</form>';
            echo '</div></div></div></div>';
            exit;
        }

        $user_profit = $this->modelTransferToMobile->{$methosProfit};

        if (isset($post['TransferToMobile']['amountValuesBDT']) && $post['TransferToMobile']['amountValuesBDT'] > 0) {

            $this->user_cost   = ($post['TransferToMobile']['amountValuesBDT'] * $this->cost) - ($post['TransferToMobile']['amountValuesBDT'] * $this->cost) * ($user_profit / 100);
            $this->user_profit = ($post['TransferToMobile']['amountValuesBDT'] * $this->cost) * ($user_profit / 100);
        } else {
            $this->user_cost   = $this->cost - ($this->cost * ($user_profit / 100));
            $this->user_profit = $this->cost * ($user_profit / 100);

        }

        if ($this->test == true) {
            echo 'cost=' . $this->cost . ', user_profit= ' . $this->cost * ($this->user_profit / 100) . ' user_profit=' . $this->user_profit . "<BR>";
        }

        if ($this->modelTransferToMobile->id_user > 1) {

            //check if agent have credit

            $modelAgent = User::model()->findByPk($this->modelTransferToMobile->id_user);

            if ($modelAgent->credit + $modelAgent->creditlimit < $this->cost) {

                echo '<div id="container"><div id="form"> <div id="box-4"><div class="control-group">';
                echo '<form action="" id="form1" method="POST">';
                echo '<font color=red>ERROR:Your Agent no have enough credit to transfer</font>';
                echo '</form>';
                echo '</div></div></div></div>';
                exit;
            }

            $agentProfit = $modelAgent->{$methosProfit};

            $this->agent_cost = $this->cost - ($this->cost * ($agentProfit / 100));

            if ($this->test == true) {
                echo 'agentProfit=' . $agentProfit . ' | $this->agent_cost=' . $this->agent_cost . "<BR>";
            }
        }
    }

    public function actionGetBuyingPrice()
    {

        $currency = $this->config['global']['fm_transfer_currency'];

        $modelSendCreditProducts = SendCreditProducts::model()->findByPk((int) $_GET['id']);

        Yii::app()->session['operatorId'] = $modelSendCreditProducts->operator_id;

        $cost = $modelSendCreditProducts->wholesale_price;

        $user_profit = $this->modelTransferToMobile->transfer_international_profit;

        $user_cost = $cost - ($cost * ($user_profit / 100));
        echo $currency . ' ' . number_format($user_cost, 2);

    }

    public function confirmRefill()
    {

        $product = $_POST['TransferToMobile']['amountValues']; //is the amout to refill

        $modelSendCreditRates = SendCreditRates::model()->find(array(
            'condition' => 'id_user = :key',
            'params'    => array(
                ':key'  => Yii::app()->session['id_user'],
                ':key1' => $_POST['TransferToMobile']['amountValues'],
            ),
            'with'      => array(
                'idProduct' => array(
                    'condition' => 'idProduct.id =  :key1',
                ),
            ),
        ));

        if (preg_match_all('/-/', $modelSendCreditRates->idProduct->product) && isset($_POST['TransferToMobile']['amountValuesEUR']) && $_POST['TransferToMobile']['amountValuesEUR'] > 0) {
            $modelSendCreditRates->sell_price = $_POST['TransferToMobile']['amountValuesEUR'];

        }

        if (!isset($modelSendCreditRates->id)) {

            echo '<div align=center id="container">';
            echo Yii::app()->session['id_user'] . ' ' . $product . ' ' . Yii::app()->session['operatorId'] . "<br>";
            echo '<font color=red>ERROR: 413</font><br><br>';
            echo '<a href="../../index.php/transferToMobile/read">Start new request </a>' . "<br><br>";
            echo '</div>';
            exit;
        }

        $this->sell_price                     = $modelSendCreditRates->sell_price;
        $this->cost                           = $modelSendCreditRates->idProduct->wholesale_price;
        $this->local_currency                 = $modelSendCreditRates->idProduct->currency_dest;
        $this->operator_name                  = $modelSendCreditRates->idProduct->operator_name;
        $this->modelTransferToMobile->product = $product = $modelSendCreditRates->idProduct->product;

        $this->calculateCost($product, $_POST);
        /*
        echo '<pre>';
        print_r($_POST);

        echo 'sell_price ' . $this->sell_price . "<br>";
        echo 'cost ' . $this->cost . "<br>";
        echo 'local_currency ' . $this->local_currency . "<br>";
        echo 'operator_name ' . $this->operator_name . "<br>";
        echo 'product ' . $this->modelTransferToMobile->product . "<br>";

        echo 'user_cost ' . $this->user_cost . "<br>";

        echo 'user_profit ' . $this->user_profit . "<br>";

        echo $modelSendCreditRates->idProduct->provider . "<br>";
        exit;
         */

        $this->addInDataBase();

        if ($this->test == true) {
            echo "REMOVE " . $this->user_cost . " from user " . $this->modelTransferToMobile->username . "<br>";
        }

        if ($modelSendCreditRates->idProduct->provider == 'Reload') {
            $result = SendCreditReload::sendCredit($this->number, $modelSendCreditRates, $this->test);
        } elseif ($modelSendCreditRates->idProduct->provider == 'Ding') {
            $result = SendCreditDingConnect::sendCredit($this->number, $modelSendCreditRates->idProduct->send_value, $modelSendCreditRates->idProduct->SkuCode, $this->test);
        } else if ($modelSendCreditRates->idProduct->provider == 'Orange2') {
            $result = SendCreditOrange2::sendCredit($this->number, $modelSendCreditRates, $this->test);
        } else {
            $result = $this->sendActionTransferToMobile('topup', $product);
        }

        $this->checkResult($result);

        $this->updateDataBase();
        exit;

    }

    public function checkResult($result)
    {

        $result = explode("error_txt=", $result);

        if (preg_match("/Transaction successful/", $result[1])) {
            $this->releaseCredit($result, '');

        } else {
            echo '<div align=center id="container">';
            echo '<font color=red>ERROR: ' . $result[1] . '</font><br><br>';
            echo '<a href="../../index.php/transferToMobile/read">Start new request </a>' . "<br><br>";
            echo '</div>';
            exit;
        }

    }

    public function releaseCredit($result, $status)
    {

        if (preg_match('/Orange2/', $result[1])) {
            $result = explode("=", $result[1]);
        }

        if ($status != 'error') {
            User::model()->updateByPk(Yii::app()->session['id_user'],
                array(
                    'credit' => new CDbExpression('credit - ' . $this->user_cost),
                )
            );
        }

        if ($this->test == true) {
            echo 'cost=' . $this->cost . '. user_cost=' . $this->user_cost . "<br>";
            echo $this->modelTransferToMobile->transfer_show_selling_price . "<br>";
        }

        $argument = $this->modelTransferToMobile->transfer_show_selling_price;
        if ($argument < 10) {
            $fee = '1.0' . $argument;
        } else {
            $fee = '1.' . $argument;
        }

        if ($this->test == true) {
            echo $fee . "<br>";
            echo $this->modelTransferToMobile->transfer_show_selling_price;
        }

        $this->showprice = number_format($this->cost * $fee, 2);

        if ($result[1] == 'Orange2') {

            $description = 'Send Credit ' . $this->local_currency . ' ' . $this->modelTransferToMobile->product . ' - +' . $this->number . ' via ' . $this->operator_name . ' - EUR ' . $this->sell_price . '. TransactionID =' . $result[2];

            if (isset($_POST['TransferToMobile']['metric']) && strlen($_POST['TransferToMobile']['metric'])) {
                $description .= ' - Meter ' . $_POST['TransferToMobile']['metric'];
                # code...
            }
        } else {
            $description = 'Send Credit ' . $this->local_currency . ' ' . $this->modelTransferToMobile->product . ' - +' . $this->number . ' via ' . $this->operator_name . ' - EUR ' . $this->sell_price;
        }

        if ($this->test == true) {
            echo $description;
        }

        $payment = 1;
        $values  = ":id_user, :costUser, :description, $payment";
        $field   = 'id_user,credit,description,payment';

        $values .= "," . $this->send_credit_id;
        $field .= ',invoice_number';

        $sql     = "INSERT INTO pkg_refill ($field) VALUES ($values)";
        $command = Yii::app()->db->createCommand($sql);
        $command->bindValue(":id_user", Yii::app()->session['id_user'], PDO::PARAM_INT);
        $command->bindValue(":costUser", $this->user_cost * -1, PDO::PARAM_STR);
        $command->bindValue(":description", $description, PDO::PARAM_STR);
        $command->execute();

        $msg = $result[1];
        if ($result[1] == 'Orange2') {
            $msg = $result[0] . "<br>TransactionID " . $result[2];
        }
        echo '<div align=center id="container">';
        echo '<font color=green>Success: ' . $msg . '</font>' . "<br><br>";
        echo '<a href="../../index.php/transferToMobile/read">Start new request </a>' . "<br><br>";
        echo '<a href="../../index.php/transferToMobile/printRefill?id=' . Yii::app()->db->lastInsertID . '">Print Refill </a>' . "<br><br>";
        echo '</div>';

        if ($this->test == true) {
            echo $sql . "<br>";
        }

        if ($this->modelTransferToMobile->id_user > 1) {
            if ($status != 'error') {
                $sql     = "UPDATE  pkg_user SET credit = credit - :costAgent WHERE id = :id";
                $command = Yii::app()->db->createCommand($sql);
                $command->bindValue(":id", $this->modelTransferToMobile->id_user, PDO::PARAM_INT);
                $command->bindValue(":costAgent", $this->agent_cost, PDO::PARAM_STR);
                $command->execute();
            }

            $payment = 1;
            $values  = ":id_user, :costAgent, :description, $payment";
            $field   = 'id_user,credit,description,payment';

            $values .= ",$this->send_credit_id";
            $field .= ',invoice_number';

            if ($this->test == true) {
                echo 'UPDATE AGENT CREDIT -> ' . $sql . "<br>";
            }

            $sql     = "INSERT INTO pkg_refill ($field) VALUES ($values)";
            $command = Yii::app()->db->createCommand($sql);
            $command->bindValue(":id_user", $this->modelTransferToMobile->id_user, PDO::PARAM_INT);
            $command->bindValue(":costAgent", $this->agent_cost * -1, PDO::PARAM_STR);
            $command->bindValue(":description", $description, PDO::PARAM_STR);
            $command->execute();

            if ($this->test == true) {
                echo 'INSERT AGENT REFILL -> ' . $sql . "<br>";
            }

        }
    }

    public function actionMsisdn_info()
    {

        $number = $this->number;

        $number = preg_match("/\+/", $number) ? $number : '+' . $number;

        if (isset($this->config['global']['fm_transfer_to_username'])) {

            $modelRefill = Refill::model()->find('description LIKE :key AND date BETWEEN :key1 AND  NOW() AND payment = 1 AND id_user = :key2',
                array(
                    ':key'  => "%" . $number . "%",
                    ':key1' => date('Y-m-d H:i', mktime(date('H'), date('i') - 2, date('s'), date('m'), date('d'), date('Y'))),
                    ':key2' => Yii::app()->session['id_user'],
                ));
            if (isset($modelRefill->id)) {
                echo '<div align=center id="container">';
                echo "<font color=red>You already send credit to this number. Wait minimal 2 minutes to new recharge</font>";
                echo '</div>';
                exit;
            }

            $result = $this->sendActionTransferToMobile('msisdn_info');

            //print_r($result);
            if (preg_match("/Transaction successful/", $result)) {
                $resultArray = explode("\n", $result);
                $tmp         = explode('=', $resultArray[3]);
                $operatorid  = trim(end($tmp));
            } else {
                //echo "Not foun product from TrasnferRo, try ding";
                //request provider code to ding and chech if exist products to Ding
                $operatorid = SendCreditDingConnect::getProviderCode($this->number);
            }
            //find products whit trasnferto operatorid
            $modelSendCreditProducts = SendCreditProducts::model()->findAll('operator_id = :key AND status = 1', array(':key' => $operatorid));

            if (!count($modelSendCreditProducts)) {

                //not receive Operator ID FROM API. API OFF LINE. GET operator from country_code
                $numberFormate           = $this->number;
                $numberFormate           = substr($numberFormate, 0, 2) == '00' ? substr($numberFormate, 2) : $numberFormate;
                $modelSendCreditProducts = SendCreditProducts::model()->findAll('country_code = SUBSTRING(:key,1,length(country_code)) AND status = 1',
                    array(
                        ':key' => $numberFormate,
                    ));
                $forceOperatorSelect = true;
                // echo $modelSendCreditProducts[0]->id . ' ' . $modelSendCreditProducts[0]->country_code;

            }

            if (!isset($modelSendCreditProducts[0])) {

                echo '<div align=center id="container">';
                echo "<font color=red>ERROR. No exist product to this number. Contact admin</font><br><br>";
                echo '<a href="../../index.php/transferToMobile/read">Start new request </a>' . "<br><br>";
                echo '</div>';
                exit;
            }

            $modelSendCreditProducts = SendCreditProducts::model()->findAll('status = 1 AND operator_name = :key AND country_code =:key1 AND type = :key2',
                array(
                    ':key'  => $modelSendCreditProducts[0]->operator_name,
                    ':key1' => $modelSendCreditProducts[0]->country_code,
                    ':key2' => 'Mobile Credit',
                ));

            $ids_products = array();
            foreach ($modelSendCreditProducts as $key => $products) {
                $ids_products[] = $products->id;
            }
            //get the user prices to mount the amount combo
            $criteria = new CDbCriteria();
            $criteria->addInCondition('id_product', $ids_products);
            $criteria->addCondition('id_user = :key');
            $criteria->params[':key'] = Yii::app()->session['id_user'];

            $modelSendCreditRates = SendCreditRates::model()->findAll($criteria);

            if (!count($modelSendCreditRates)) {
                exit('Before send credit, you need add your sell price');
            }

            $values = array();
            $i      = 0;

            foreach ($modelSendCreditProducts as $key => $product) {

                if (is_numeric($product->product)) {

                    if ($this->test == true) {
                        echo $product->id . ' -> ' . $product->currency_dest . ' ' . $product->product . ' = ' . $product->currency_orig . ' ' . trim($modelSendCreditRates[$i]->sell_price) . "<BR>";
                    }
                    $values[trim($product->id)]        = '<font size=1px>' . $product->currency_dest . '</font> ' . trim($product->product) . ' = <font size=1px>' . $product->currency_orig . '</font> ' . trim($modelSendCreditRates[$i]->sell_price);
                    Yii::app()->session['is_interval'] = false;
                } else {
                    Yii::app()->session['is_interval']                 = true;
                    Yii::app()->session['interval_currency']           = $product->currency_dest;
                    Yii::app()->session['interval_product_id']         = $product->id;
                    Yii::app()->session['interval_product_interval']   = $product->product;
                    Yii::app()->session['interval_product_sell_price'] = trim($modelSendCreditRates[$i]->sell_price);

                    Yii::app()->session['allowedAmount'] = explode('-', $product->product);
                }
                $i++;

            }

            Yii::app()->session['amounts']      = isset($forceOperatorSelect) ? array() : $values;
            Yii::app()->session['operatorId']   = $operatorid;
            Yii::app()->session['ids_products'] = $ids_products;

            $this->modelTransferToMobile->country  = $modelSendCreditProducts[0]->country;
            $this->modelTransferToMobile->operator = isset($forceOperatorSelect) ? '' : $modelSendCreditProducts[0]->operator_name;

            return $this->modelTransferToMobile;

        } else {
            return false;
        }

    }

    public function actionPrintRefill()
    {

        if (isset($_GET['id'])) {
            echo '<center>';
            $config    = LoadConfig::getConfig();
            $id_refill = $_GET['id'];

            $modelRefill = Refill::model()->findByPk((int) $id_refill, 'id_user = :key', array(':key' => Yii::app()->session['id_user']));

            echo $config['global']['fm_transfer_print_header'] . "<br><br>";

            echo $modelRefill->idUser->company_name . "<br>";
            echo $modelRefill->idUser->address . ', ' . $modelRefill->idUser->city . "<br>";
            echo "Trx ID: " . $modelRefill->id . "<br>";

            echo $modelRefill->date . "<br>";

            $number = explode(" ", $modelRefill->description);

            echo "Cellulare.: " . $number[5] . "<br>";

            if (preg_match('/Meter/', $modelRefill->description)) {
                $tmp = explode('Meter', $modelRefill->description);
                echo 'Meter: ' . $tmp[1] . "<br>";
            }

            $tmp    = explode('EUR ', $modelRefill->description);
            $tmp    = explode('. T', $tmp[1]);
            $amount = $tmp[0];

            $tmp      = explode('via ', $modelRefill->description);
            $operator = strtok($tmp[1], '-');
            $tmp      = explode('Send Credit ', $modelRefill->description);
            $tmp      = explode(' -', $tmp[1]);
            $product  = $tmp[0];

            echo 'Prodotto:  ' . $product . ' ' . $operator . "<br>";

            echo "Importo: EUR <input type=text' style='text-align: right;' size='6' value='$amount'> <br><br>";

            echo $config['global']['fm_transfer_print_footer'] . "<br><br>";

            echo '<td><a href="javascript:window.print()">Print</a></td>';
            echo '</center>';
        } else {
            echo ' Invalid reffil';
        }
    }

    public function actionGetProducts()
    {

        $modelSendCreditProducts = SendCreditProducts::model()->findAll(array(
            'condition' => 'operator_name = :key AND status = 1',
            'params'    => array(':key' => $_GET['operator']),
        ));

        $operatorId = $modelSendCreditProducts[0]->operator_id;

        $ids_products = array();
        foreach ($modelSendCreditProducts as $key => $products) {
            $ids_products[] = $products->id;
        }
        //get the user prices to mount the amount combo
        $criteria = new CDbCriteria();
        $criteria->addInCondition('id_product', $ids_products);
        $criteria->addCondition('id_user = :key');
        $criteria->params[':key'] = Yii::app()->session['id_user'];

        $modelSendCreditRates = SendCreditRates::model()->findAll($criteria);

        $values = array();
        $i      = 0;

        ?>
            <?php foreach ($modelSendCreditProducts as $key => $product): ?>

                <?php if (is_numeric($product->product)): ?>

                    <?php Yii::app()->session['is_interval'] = false;?>
                <label for="2" class="company__row" id="productLabel<?php echo $i ?>">
                        <input type="radio"  id="productinput<?php echo $i ?>" name="amountValues" value="<?php echo $product->id ?>">
                        <div  class="company__logo-container" onclick="handleChange1(<?php echo $i ?>,<?php echo count($modelSendCreditProducts) ?>);" id='product<?php echo $i ?>' >
                            <?php echo '<font size=1px>' . $product->currency_dest . ' </font>' . $product->product . ' = <font size=1px>' . $product->currency_orig . ' </font>' . $modelSendCreditRates[$i]->sell_price ?>

                            </div>
                    </label>
                    <?php $i++;?>
                <?php else: ?>

                        <?php Yii::app()->session['is_interval']                 = true;?>
                        <?php Yii::app()->session['interval_currency']           = $product->currency_dest;?>
                        <?php Yii::app()->session['interval_product_id']         = $product->id;?>
                        <?php Yii::app()->session['interval_product_interval']   = $product->product;?>
                        <?php Yii::app()->session['interval_product_sell_price'] = trim($modelSendCreditRates[$i]->sell_price);?>
                        <?php Yii::app()->session['allowedAmount']               = explode('-', $product->product)?>
                <?php endif?>
            <?php endforeach;?>

        <?php

        if (Yii::app()->session['is_interval'] == true) {
            echo '|is_interval';
        }
        Yii::app()->session['amounts']    = $values;
        Yii::app()->session['operatorId'] = $operatorId;

    }

    public function actionGetProductTax()
    {

        $modelSendCreditProducts = SendCreditProducts::model()->findByPk((int) $_GET['id']);
        echo $modelSendCreditProducts->info;
    }
}
