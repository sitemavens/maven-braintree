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
if ( !defined( 'ABSPATH' ) )
    exit;


//If the validation was already loaded
if ( !class_exists( 'MavenValidation' ) ) {
    require_once plugin_dir_path( __FILE__ ) . 'maven-validation.php';
}

require_once plugin_dir_path( __FILE__ ) . '/includes/Braintree.php';

// Check if Maven is activate, if not, just return.
if ( \MavenValidation::isMavenMissing() )
    return;

use Maven\Settings\OptionType,
    Maven\Settings\Option;

class MavenBrainTreeGateway extends \Maven\Gateways\Gateway {

    protected $nonce = '';
    protected $token = '';

    public function __construct () {

        parent::__construct( "Braintree" );
        add_action( 'maven/cart/beforeGatewayExecute', array( $this, 'setPaymentMethod' ) );

        $defaultOptions = array(
            new Option(
                    "currencyCode", "Currency Code", 'GBP', '', OptionType::Input
            ),
            new Option(
                    "merchantID", "Merchant ID", '', '', OptionType::Input
            ),
            new Option(
                    "environment", "Environment", '', '', OptionType::Input
            ),
            new Option(
                    "publicKey", "Public Key", '', '', OptionType::Input
            ),
            new Option(
                    "privateKey", "Private Key", '', '', OptionType::Input
            ),
        );

        $this->setParameterPrefix( "" );
        $this->setItemDelimiter( "" );

        $this->addSettings( $defaultOptions );
    }

    public function setNonce ( $nonce ) {
        $this->nonce = $nonce;
    }

    public function setToken ( $token ) {
        $this->token = $token;
    }

    public function getNonce () {
        return $this->nonce;
    }

    public function getToken () {
        return $this->token;
    }

    public function setPaymentMethod ( $order ) {
        if ( $order->getPayToken() ) {
            $this->setToken( $order->getPayToken() );
        } else if ( $order->getPayNonce() ) {
            $this->setNonce( $order->getPayNonce() );
        }
    }
    private $order;

    /**
     * 
     * @param array $args 
     * 
     */
    public function execute () {
        $this->configureGateway();
        $args = array(
            'amount' => $this->getAmount(),
            "options" => array(
                "submitForSettlement" => true
            )
        );

        if ( $this->getNonce() ) {
            $args['paymentMethodNonce'] = $this->getNonce();
        } else if ( $this->getToken() ) {
            $args['paymentMethodToken'] = $this->getToken();
        } else {
            $args['creditCard'] = array(
                "number" => $this->getCCNumber(),
                "cvv" => $this->getCCVerificationCode(),
                "expirationMonth" => $this->getCCMonth(),
                "expirationYear" => $this->getCCYear()
            );
        }
        $result = \Braintree_Transaction::sale( $args );
        if ( $result->success ) {
            $this->setApproved( true );
            $this->setTransactionId( $result->transaction->id );
        } else if ( $result->transaction ) {
            $this->setApproved( false );
            $this->setErrorDescription( sprintf( __( 'Payment declined - Error: %s - Code: %s', 'woocommerce' ), $result->message, $result->transaction->processorResponseCode ) );
        } else {
            $this->setApproved( false );
            $errors = 'Validation error - ';
            foreach ( ($result->errors->deepAll() ) as $error ) {
                $errors .= $error->message . " ";
            }
            $this->setErrorDescription( $errors );
        }
    }

    public function getAvsCode () {}

    private function configureGateway () {
        \Braintree_Configuration::environment( $this->getSetting( 'environment' ) );
        \Braintree_Configuration::merchantId( $this->getSetting( 'merchantID' ) );
        \Braintree_Configuration::publicKey( $this->getSetting( 'publicKey' ) );
        \Braintree_Configuration::privateKey( $this->getSetting( 'privateKey' ) );
    }

    public function register ( $gateways ) {

        $gateways[$this->getKey()] = $this;

        return $gateways;
    }

    public function generateToken ( $id, $profile = array() ) {
        $this->configureGateway();

        $result = $this->getCustomer( $id, $profile );
        if ( $result ) {
            $clientToken['token'] = \Braintree_ClientToken::generate( array(
                        "customerId" => $result->id
            ) );
            return $clientToken;
        }
        return false;
    }

    public function clientExists ( $id ) {
        try {
            $customer = \Braintree_Customer::find( $id );
            return $customer;
        } catch ( \Exception $e ) {
            return false;
        }
    }

    public function createClient ( $id, $profile = array() ) {
        try {
            $args['id'] = $id;
            if ( isset( $profile['email'] ) ) {
                $args['email'] = $profile['email'];
            }
            $result = \Braintree_Customer::create( $args );
            return $result->customer;
        } catch ( \Exception $e ) {
            return false;
        }
    }

    private function getCustomer ( $id, $profile ) {
        $customer = $this->clientExists( $id );
        if ( !$customer ) {
            $customer = $this->createClient( $id, $profile );
        }
        return $customer;
    }

}

$MavenBrainTreeGateway = new MavenBrainTreeGateway();
\Maven\Core\HookManager::instance()->addFilter( 'maven/gateways/register', array( $MavenBrainTreeGateway, 'register' ) );


