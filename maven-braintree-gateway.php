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

    private function getOrderResponse ( $xml ) {
        $this->raw_response = $xml;
        $x = @simplexml_load_string( $xml );

        $node = $x->reply;
        if ( $node ) {
            if ( $node->orderStatus ) {
                $node = $node->orderStatus;
                if ( $node ) {
                    if ( $node->error ) {
                        $this->error = true;
                        $error_desc = ( string ) $node->error;
                        $error_raw = $error_desc;

                        if ( strpos( $error_desc, "The Payment Method is not available" ) > 0 )
                            $error_desc = "We are sorry but this transaction has been denied. There are a variety of possible reasons for this, which we can help you to resolve. Please double check your information or reach out to us for assistance via email at <a href='mailto:pablo@peterharrington.co.uk'>pablo@peterharrington.co.uk</a>.";

                        $this->setErrorDescription( $error_desc );
                    } else if ( $node->payment ) {

                        if ( $node->payment->lastEvent && $node->payment->lastEvent == 'REFUSED' ) {
                            $this->setApproved( false );
                            $this->setDeclined( true );
                            $this->setError( true );
                            $this->setErrorDescription( "Credit card declined" );
                            $this->setTransactionId( -1 );
                        } else {
                            $this->setApproved( true );
                            $this->setTransactionId( ( string ) $node->attributes()->orderCode );
                        }
                    }
                }
            } else {
                if ( $node->error ) {

                    $this->setError( true );
                    $this->setErrorDescription( ( string ) $node->error );
                }
            }
        } else {
            $this->setError( true );
            //$this->setErrorDescription( "Error 201- We are sorry but this transaction has been denied. There are a variety of possible reasons for this, which we can help you to resolve. Please double check your information or reach out to us for assistance via email at <a href='mailto:pablo@peterharrington.co.uk'>pablo@peterharrington.co.uk</a>.";
        }
    }

    private function getFormatedAmount () {

        $amount = $this->getAmount();
        $amount = number_format( $amount, 2 );

        // WorldPay wants an amount without any decimals or commas
        $n = preg_replace( '/[^0-9]/', '', $amount );
        $amount = ( $amount < 0) ? ('-' . $n) : $n;

        return $amount;
    }

//	"invoice_num"
//		"description"
//		"amount"
    private function getOrderXML ( $isFollowUpRequest ) {
        $xml = $this->getXmlWriter();
        $xml->startElement( 'submit' );
        $xml->startElement( 'order' );
        $xml->writeAttribute( 'orderCode', $this->getInvoiceNumber() );

        $xml->writeElement( 'description', $this->getDescription() );

        $this->addAmountElement( $xml );

        $xml->startElement( 'orderContent' );
        $xml->writeCdata( '<center></center>' );
        $xml->endElement();

        $xml->startElement( 'paymentDetails' );
        $this->addCreditCardElement( $xml );
        $this->addSessionElement( $xml );

        $xml->endElement(); // paymentDetails

        $xml->startElement( 'shopper' );
        $xml->writeElement( 'shopperEmailAddress', $this->getEmail() );
        $this->addBrowserDetails( $xml );
        $xml->endElement(); //shopper

        $xml->startElement( 'shippingAddress' );
        $this->addAddressNode( $xml, 'ship' );
        $xml->endElement(); // shippingAddress

        $xml->endElement(); // order
        $xml->endElement(); // submit
        $xml->endElement(); // paymentService

        return $xml->outputMemory( true );
    }

    private function addCreditCardElement ( \XMLWriter $xml ) {

        $xml->startElement( $this->getCreditCardMethodCode() );
        $xml->writeElement( 'cardNumber', $this->getCCNumber() );
        $xml->startElement( 'expiryDate' );
        $xml->startElement( 'date' );
        $xml->writeAttribute( 'month', $this->getCCMonth() );
        $xml->writeAttribute( 'year', $this->getCCYear() );
        $xml->endElement(); // date
        $xml->endElement(); // expiryDate

        $xml->writeElement( 'cardHolderName', $this->getCCHolderName() );

        $cvv2 = $this->getCCVerificationCode();

//		if ( !$cvv2 ) {
//			$cvv2 = $this->getParameter( 'card_code' );
//		}

        if ( $cvv2 ) {
            $xml->writeElement( 'cvc', $cvv2 );
        }

        $xml->startElement( 'cardAddress' );
        $this->addAddressNode( $xml, 'bill' );
        $xml->endElement(); // cardAddress
        $xml->endElement(); // cardType
    }

    private function getExpirationMonth () {
        return str_pad( $this->getParameter( 'exp_month' ), 2, '0', STR_PAD_LEFT );
    }

    private function addAddressNode ( \XMLWriter $xml, $paramType ) {
        $xml->startElement( 'address' );
        $xml->writeElement( 'firstName', $this->getFirstName() );
        $xml->writeElement( 'lastName', $this->getLastName() );
        //$xml->writeElement('street',          trim($this->get_parameter('address')));

        $xml->startElement( 'street' );
        $xml->writeCdata( trim( $this->getAddress() ) );
        $xml->endElement();


        $xml->startElement( 'postalCode' );
        $xml->writeCdata( trim( $this->getZip() ) );
        $xml->endElement();

        //$xml->writeElement('postalCode',      $this->get_parameter('zip'));
        $xml->writeElement( 'city', $this->getCity() );
        $xml->writeElement( 'countryCode', $this->getCountry() );
        //$xml->writeElement('telephoneNumber', $this->getParam('bill_phone')); // we don't have a ship_phone, so always use bill_phone
        $xml->endElement(); // address
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

    public function getAvsCode () {
        
    }

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


