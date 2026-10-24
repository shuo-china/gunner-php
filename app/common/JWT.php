<?php
namespace app\common;

use \Exception;

class JWT
{
    /**
     * 加密
     * @param  array  $payload
     * @param  string $secret
     * @return string
     */
    public static function encode($payload, $secret)
    {
        $header = ['typ' => 'JWT', 'alg' => 'HS256'];
        $segments = [];
        $segments[] = self::base64UrlEncode(json_encode($header));
        $segments[] = self::base64UrlEncode(json_encode($payload));
        $signingInput = implode('.', $segments);
        $signature = self::sign($signingInput, $secret);
        $segments[] = self::base64UrlEncode($signature);
        return implode('.', $segments);
    }

    /**
     * 解密
     * @param  string $jwt
     * @param  string $secret
     * @return array
     * @throws Exception
     */
    public static function decode($jwt, $secret)
    {
        $timestamp = time();

        $tks = explode('.', $jwt);
        if (count($tks) != 3) {
            throw new Exception('Wrong number of segments');
        }

        list($headerb64, $payload64, $sign64) = $tks;
        if (($header = json_decode(self::base64UrlDecode($headerb64), true)) === null) {
            throw new Exception('Invalid header encoding');
        }
        if (($payload = json_decode(self::base64UrlDecode($payload64), true)) === null) {
            throw new Exception('Invalid claims encoding');
        }
        if (($sign = self::base64UrlDecode($sign64)) === false) {
            throw new Exception('Invalid signature encoding');
        }
        if (empty($header['alg'])) {
            throw new Exception('Empty algorithm');
        }
        if ($header['alg'] !== 'HS256') {
            throw new Exception('Algorithm not supported');
        }
        if (!self::verify("$headerb64.$payload64", $secret, $sign)) {
            throw new Exception('Signature verification failed');
        }
        // 签发人
        if (isset($payload['iss']) && $payload['iss'] !== request()->domain()) {
            throw new Exception('Issuer verfication failed');
        }
        // Not Before
        if (isset($payload['nbf']) && $payload['nbf'] > $timestamp) {
            throw new Exception('Cannot handle token prior to ' . date(DATE_ISO8601, $payload['nbf']));
        }
        // 签发时间
        if (isset($payload['iat']) && $payload['iat'] > $timestamp) {
            throw new Exception('Cannot handle token prior to ' . date(DATE_ISO8601, $payload['iat']));
        }
        // 过期时间
        if (isset($payload['exp']) && $payload['exp'] <= $timestamp) {
            throw new Exception('Expired token');
        }
        return $payload;
    }

    private static function sign($data, $secret)
    {
        return hash_hmac('SHA256', $data, $secret, true);
    }

    private static function verify($data, $secret, $signature)
    {
        return hash_equals($signature, self::sign($data, $secret));
    }

    private static function base64UrlEncode($input)
    {
        return str_replace('=', '', strtr(base64_encode($input), '+/', '-_'));
    }

    private static function base64UrlDecode($input)
    {
        $remainder = strlen($input) % 4;
        if ($remainder) {
            $padlen = 4 - $remainder;
            $input .= str_repeat('=', $padlen);
        }
        return base64_decode(strtr($input, '-_', '+/'));
    }
}