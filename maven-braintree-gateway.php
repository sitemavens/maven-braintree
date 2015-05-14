<?php

/*
Plugin Name: Maven Braintree Gateway
Plugin URI:
Description:
Author: Site Mavens
Version: 0.1
Author URI:
 */

namespace MavenBrainTreeGateway;

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

//If the validation was already loaded
if (!class_exists('MavenValidation')) {
    require_once plugin_dir_path(__FILE__) . 'maven-validation.php';
}

require_once plugin_dir_path(__FILE__) . '/includes/Braintree.php';

// Check if Maven is activate, if not, just return.
if (\MavenValidation::isMavenMissing()) {
    return;
}

use Maven\Settings\Option;
use Maven\Settings\OptionType;
use Maven\Core\UserApi;

class MavenBrainTreeGateway extends \Maven\Gateways\Gateway {

    /**
     *
     * @var \Maven\Core\Domain\Order
     */
    private $result = false;
    protected $nonce = '';
    protected $token = '';
    protected $creditCard = '';

    public function __construct() {

        parent::__construct("Braintree");
        add_action('maven/cart/beforeGatewayExecute', array($this, 'setPaymentMethod'));

        $defaultOptions = array(
            new Option(
                "currencyCode", "Currency Code", 'GBP', '', OptionType::Input
            ),
            new Option(
                "merchantID", "Merchant ID", 'zj4r4jf26xxrxmq6', '', OptionType::Input
            ),
            new Option(
                "environment", "Environment", 'sandbox', '', OptionType::Input
            ),
            new Option(
                "publicKey", "Public Key", 'c3qrbhznwmnwk8ff', '', OptionType::Input
            ),
            new Option(
                "privateKey", "Private Key", 'b629e52057c7781f358c52d40cb407eb', '', OptionType::Input
            ),
        );
        wp_enqueue_script( 'braintree', plugin_dir_url(__FILE__) . '/includes/lib/braintree.js', array(), '', true );
        $this->setParameterPrefix("");
        $this->setItemDelimiter("");

        $this->addSettings($defaultOptions);
    }

    public function setNonce($nonce) {
        $this->nonce = $nonce;
    }

    public function setToken($token) {
        $this->token = $token;
    }

    public function getNonce() {
        return $this->nonce;
    }

    public function getToken() {
        return $this->token;
    }

    /**
     * Return the result
     * @return \Maven\Core\Message\Message
     */
    public function getResult() {
        return $this->result;
    }

    /**
     * Set the cart result
     * @param \Maven\Core\Message\Message $message
     * @return type
     */
    public function setResult(\Maven\Core\Message\Message $message) {
        $this->result = $message;
        return $this->result;
    }

    public function setPaymentMethod($order) {
        if ($order->getPayToken()) {
            $this->setToken($order->getPayToken());
        } else if ($order->getPayNonce()) {
            $this->setNonce($order->getPayNonce());
        }
    }
    private $order;

    /**
     *
     * @param array $args
     *
     */
    public function execute() {
        $this->configureGateway();
        $args = array(
            'amount'  => $this->getAmount(),
            'options' => array( 'submitForSettlement' => true )
        );

        // === NEW CODE MTZ
        $userApi = new UserApi();
        $user    = $userApi->getLoggedInUser();
        if ( $user && $this->getNonce()) {
            $profile      = $user->getProfile();
            $cratePayment = $this->createPaymentMethod([
                'customerId'         => $profile->getUserId(),
                'paymentMethodNonce' => $this->getNonce(),
                'options'            => array( 'failOnDuplicatePaymentMethod' => true )
            ]);

            $args['customerId'] = $profile->getUserId();
            $this->setNonce('');
        }

        if ($this->getNonce()) {
            $args['paymentMethodNonce'] = $this->getNonce();
        } else if ($this->getToken()) {
            $args['paymentMethodToken'] = $this->getToken();
        } else {
            $args['creditCard'] = array(
                "cardholderName"  => $this->getCCHolderName(),
                "number"          => $this->getCCNumber(),
                "cvv"             => $this->getCCVerificationCode(),
                "expirationMonth" => $this->getCCMonth(),
                "expirationYear"  => $this->getCCYear(),
            );
        }

        $result = \Braintree_Transaction::sale($args);
        
        if ($result->success) {
            $this->setApproved(true);
            $this->setTransactionId($result->transaction->id);
        } else if ($result->transaction) {
            $this->setApproved(false);
            $this->setErrorDescription(sprintf(__('Payment declined - Error: %s - Code: %s', 'woocommerce'), $result->message, $result->transaction->processorResponseCode));
        } else {
            $this->setApproved(false);
            $errors = 'Validation error - ';
            foreach (($result->errors->deepAll()) as $error) {
                $errors .= $error->message . " ";
            }
            $this->setErrorDescription($errors);
        }
    }

    public function getAvsCode() {}

    private function configureGateway() {
        \Braintree_Configuration::environment($this->getSetting('environment'));
        \Braintree_Configuration::merchantId($this->getSetting('merchantID'));
        \Braintree_Configuration::publicKey($this->getSetting('publicKey'));
        \Braintree_Configuration::privateKey($this->getSetting('privateKey'));
    }

    public function register($gateways) {

        $gateways[$this->getKey()] = $this;

        return $gateways;
    }

    public function generateToken($id, $profile) {
        $this->configureGateway();

        $result = $this->getCustomer($id, $profile);
        if ($result) {
            $clientToken['token'] = \Braintree_ClientToken::generate(array(
                "customerId" => $result->id,
            ));
            return $clientToken;
        }
        return false;
    }

    public function sendNonce($nonce, $amount) {
        $this->configureGateway();
        $result = \Braintree_Transaction::sale(array(
            'amount' => $amount,
            'paymentMethodNonce' => $nonce,
        ));

        return $result;
    }

    public function clientExists($id) {
        $this->configureGateway();
        try {
            $customer = \Braintree_Customer::find($id);
            return $customer;
        } catch (\Exception $e) {
            return false;
        }
    }
    
    public function createClient($id, $profile) {
        try {
            $profile["id"] = $id;
            $result = \Braintree_Customer::create($profile);
            return $result;
        } catch (\Exception $e) {
            return false;
        }
    }

    private function getCustomer($id, $profile) {
        $customer = $this->clientExists($id);
        if (!$customer) {
            $customer = $this->createClient($id, $profile);
        }
        return $customer;
    }

    // === NEW CODE MTZ
    public function createPaymentMethod($dataPayment) {
        $this->configureGateway();
        try {
            $result = \Braintree_PaymentMethod::create($dataPayment);
            return $result;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function deletePaymentMethod($token) {
        $this->configureGateway();
        try {
            $result = \Braintree_PaymentMethod::delete($token);
            return $result;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function updatePaymentMethod($token, $profile) {
        $this->configureGateway();
        try {
            $result = \Braintree_PaymentMethod::update($token, $profile);
            return $result;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function findPaymentMethod($token) {
        $this->configureGateway();
        try {
            $result = \Braintree_PaymentMethod::find($token);
            return $result;
        } catch (\Exception $e) {
            return false;
        }
    }
}

$MavenBrainTreeGateway = new MavenBrainTreeGateway();
\Maven\Core\HookManager::instance()->addFilter('maven/gateways/register', array($MavenBrainTreeGateway, 'register'));
