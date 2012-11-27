<?php
namespace Xsolla\Sdk\Api;

use Xsolla\Sdk\Api\Client\ClientInterface;
use Xsolla\Sdk\Api\Exception\ErrorCode\MobilePaymentException;
use Xsolla\Sdk\Api\Exception\ErrorCode\ErrorCodeExceptionInterface;
use Xsolla\Sdk\Api\Exception\InvalidResponseException;

/**
 * @link http://xsolla.com/docs/mobile-payment-api
 * @author Vitaliy Zakharov <v.zakharov@xsolla.com>
 */
class MobilePayment
{
    const XSD_PATH_CALCULATE = '/mobilepayment/calculate.xsd';

    const XSD_PATH_INVOICE = '/mobilepayment/invoice.xsd';

    /**
     * @var int
     */
    private $projectId;

    /**
     * @var string
     */
    private $secretKey;

    /**
     * @var ClientInterface
     */
    private $client;

    /**
     * @var string
     */
    private $schemaDir;

    /**
     * @var string
     */
    protected $url = 'https://api.xsolla.com/mobile/payment/index.php?';

    /**
     * @param  ClientInterface           $client
     * @param  int                       $projectId Developer's ID in Xsolla system.
     * @param  string                    $secretKey Developer's secret key.
     * @param  string                    $schemaDir /path/to/Xsolla/Sdk/Resources/schema/api
     * @throws \InvalidArgumentException If one of arguments has invalid format.
     */
    public function __construct(ClientInterface $client, $projectId, $secretKey, $schemaDir = null)
    {
        if (is_null($schemaDir)) {
            $schemaDir = __DIR__.'/../Resources/schema/api';
        }
        if (!is_dir($schemaDir)) {
            throw new \InvalidArgumentException("$schemaDir is not a dir.");
        }
        if (!filter_var($projectId, FILTER_VALIDATE_INT)) {
            throw new \InvalidArgumentException("$projectId is not a int.");
        }
        if (!is_scalar($secretKey)) {
            throw new \InvalidArgumentException("$secretKey is not a string.");
        }
        $this->schemaDir = $schemaDir;
        $this->client = $client;
        $this->projectId = $projectId;
        $this->secretKey = $secretKey;
    }

    /**
     * Method is used for generating an invoice to be paid by the user.
     * @param  string                    $phone  User's phone number. Must be 10 digits. Example 9123456789.
     * @param  string                    $v1     User ID unique to developer's platform
     * @param  string                    $v2     User ID (additional). Required if v3 is present.
     * @param  string                    $v3     User ID (additional)
     * @param  float                     $sum    Payment amount in RUB. $sum and $out cannot both be present. Must contain 2 or fewer digits after the decimal point.
     * @param  float                     $out    Virtual currency amount. $sum and $out cannot both be present.
     * @param  string                    $email  User's email
     * @param  string                    $userIp User's IP address. Only public.
     * @return string                    Invoice number.
     * @throws \InvalidArgumentException If one of arguments has invalid format.
     * @throws InvalidResponseException  If network problem or response has invalid format.
     * @throws MobilePaymentException    If xsolla response is not succeed.
     */
    public function invoice($phone, $v1, $v2 = null, $v3 = null, $sum = null, $out = null, $email = null, $userIp = null)
    {
        $urlVars = array('command' => 'invoice', 'project' => $this->projectId, 'v1' => $v1);
        $stringForSignature = 'invoice'.$this->projectId.$v1;
        if (!is_null($v2)) {
            $urlVars['v2'] = $v2;
            $stringForSignature .= $v2;
            if (!is_null($v3)) {
                $urlVars['v3'] = $v3;
                $stringForSignature .= $v3;
            }
        }
        //sum & out
        if (!is_null($sum) AND !is_null($out)) {
            throw new \InvalidArgumentException('Sum and out cannot both be present.');
        } elseif (!is_null($sum)) {
            if (!$this->isValidAmountFormat($sum, true)) {
               throw new \InvalidArgumentException("$sum - out has invalid format.");
            }
            $urlVars['sum'] = $sum;
            $stringForSignature .= $sum;
        } elseif (!is_null($out)) {
            if (!$this->isValidAmountFormat($out, false)) {
               throw new \InvalidArgumentException("$out - out has invalid format.");
            }
            $urlVars['out'] = $out;
            $stringForSignature .= $out;
        } else {
            throw new \InvalidArgumentException('Sum or out must be passed.');
        }
        //phone
        if (!$this->isValidPhoneFormat($phone)) {
            throw new \InvalidArgumentException("$phone - phone number must contain only digits and have a length of 10 characters");
        }
        $urlVars['phone'] = $phone;
        $stringForSignature .= $phone;
        //ip
        if (!is_null($userIp)) {
            if (!filter_var($userIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new \InvalidArgumentException;
            }
            $urlVars['userip'] = $userIp;
            $stringForSignature .= $userIp;
        }
        //email
        if (!is_null($email)) {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new \InvalidArgumentException;
            }
            $urlVars['email'] = $email;
            $stringForSignature .= $email;
        }
        $xsollaResponse = $this->send($urlVars, $stringForSignature, self::XSD_PATH_INVOICE);
        if (!isset($xsollaResponse['invoice'])) {
            throw new InvalidResponseException('Xsolla response doesn\'t contain invoice number.');
        }

        return $xsollaResponse['invoice'];
    }

    /**
     * Calculating the payment amount in roubles that the usershould pay in order to receive a certain amount of virtual currency.
     * @param  string                      $phone User's phone number. Must be 10 digits. Example 9123456789.
     * @param  float                       $out   Virtual currency amount.
     * @return float                       Payment amount in RUB.
     * @throws \InvalidArgumentException   If phone or sum has invalid format
     * @throws InvalidResponseException    If network problem or response has invalid format.
     * @throws ErrorCodeExceptionInterface If xsolla response is not succeed.
     */
    public function calculateSum($phone, $out)
    {
        if (!is_scalar($out)) {
            throw new \InvalidArgumentException("out has invalid format");
        }
        if (!$this->isValidAmountFormat($out)) {
            throw new \InvalidArgumentException("$out - out has invalid format");
        }

        return $this->calculate($phone, $out, 'out');
    }

    /**
     * Calculating the virtual currency amount the user receives for their payment in roubles.
     * @param  string                      $phone User's phone number. Must be 10 digits. Example 9123456789.
     * @param  float                       $sum   Payment amount in RUB. Must contain 2 or fewer digits after the decimal point.
     * @return float                       Virtual currency amount.
     * @throws \InvalidArgumentException   If phone or sum has invalid format
     * @throws InvalidResponseException    If network problem or response has invalid format.
     * @throws ErrorCodeExceptionInterface If xsolla response is not succeed.
     */
    public function calculateOut($phone, $sum)
    {
        if (!is_scalar($sum)) {
            throw new \InvalidArgumentException("sum has invalid format");
        }
        if (!$this->isValidAmountFormat($sum, true)) {
            throw new \InvalidArgumentException("$sum - sum has invalid format");
        }

        return $this->calculate($phone, $sum, 'sum');
    }

    protected function send(array $urlVars, $stringForSignature, $schemaFile)
    {
        $urlVars['md5'] = md5($stringForSignature.$this->secretKey);
        $url = $this->url.http_build_query($urlVars);
        $xsdFileName = $this->schemaDir.$schemaFile;
        echo $url.PHP_EOL; exit;
        $xsollaResponse = $this->client->send($url, $xsdFileName);
        if ('0' !== $xsollaResponse['result']) {
            throw new MobilePaymentException($xsollaResponse['comment'], $xsollaResponse['result']);
        }

        return $xsollaResponse;
    }

    private function calculate($phone, $number, $numberType)
    {
        if (!$this->isValidPhoneFormat($phone)) {
            throw new \InvalidArgumentException("$phone - phone number must contain only digits and have a length of 10 characters");
        }
        $urlVars = array('command' => 'calculate', 'project' => $this->projectId);
        $stringForSignature = 'calculate'.$this->projectId;
        $urlVars[$numberType] = $number;
        $stringForSignature .= $number;
        $stringForSignature .= $phone;
        $urlVars['phone'] = $phone;
        $xsollaResponse = $this->send($urlVars, $stringForSignature, self::XSD_PATH_CALCULATE);
        if ('sum' === $numberType) {
            return (float) $xsollaResponse['out'];
        }

        return (float) $xsollaResponse['sum'];
    }

    private function isValidAmountFormat($sum, $isMoney = false)
    {
        if ($isMoney) {
            $pattern = '~^\d+(\.\d{1,2})?$~';
        } else {
            $pattern = '~^\d+(\.\d{1,})?$~';
        }

        return preg_match($pattern, $sum) AND (0 < $sum);
    }

    private function isValidPhoneFormat($phone)
    {
        return ctype_digit((string) $phone) AND (10 === strlen($phone));
    }

}
