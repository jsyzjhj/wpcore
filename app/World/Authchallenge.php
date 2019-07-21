<?php
namespace app\World;

use app\Common\int_helper;
use app\Common\Srp6;
use app\World\Connection;
use app\World\OpCode;
use app\World\Worldpacket;

/**
 * 世界服务器鉴权
 */
class Authchallenge
{
    /**
     * [Authchallenge 发起验证 SMSG_AUTH_CHALLENGE]
     * ------------------------------------------------------------------------------
     * @author  by.fan <fan3750060@163.com>
     * ------------------------------------------------------------------------------
     * @version date:2019-07-11
     * ------------------------------------------------------------------------------
     */
    public function Authchallenge($fd)
    {
        $hardcoded = '0x01000000';

        $Srp6      = new Srp6();
        $hardcoded = $Srp6->BigInteger($hardcoded, 16)->toBytes();

        $seed  = $Srp6->Littleendian($Srp6->_random_number_helper(4)->toHex())->toBytes();
        $seed1 = $Srp6->Littleendian($Srp6->_random_number_helper(16)->toHex())->toBytes();
        $seed2 = $Srp6->Littleendian($Srp6->_random_number_helper(16)->toHex())->toBytes();

        $data     = $hardcoded . $seed . $seed1 . $seed2;
        $data     = int_helper::getBytes($data);
        $packdata = Worldpacket::encrypter(OpCode::SMSG_AUTH_CHALLENGE, $data);
        $data     = array_merge($packdata, $data);

        // 存储
        $connectionCls = new Connection();
        $connectionCls->saveConnector($fd, ['serverseed' => $seed]);

        return $data;
    }

    /**
     * [AuthSession 解包客户端信息并验证 CMSG_AUTH_SESSION]
     * ------------------------------------------------------------------------------
     * @author  by.fan <fan3750060@163.com>
     * ------------------------------------------------------------------------------
     * @version date:2019-07-16
     * ------------------------------------------------------------------------------
     * @param   [type]          $data [description]
     */
    public function AuthSession($fd, $data)
    {
        $Srp6 = new Srp6();

        $packdata = Worldpacket::Unpackdata($data);
        $content  = int_helper::getBytes($packdata[2]);

        $packdata = [];

        // 版本
        $build             = array_slice($content, 0, 2);
        $build_0           = $Srp6->BigInteger(int_helper::toStr([$build[1]]), 256)->toHex();
        $build_1           = $Srp6->BigInteger(int_helper::toStr([$build[0]]), 256)->toHex();
        $packdata['build'] = int_helper::HexToDecimal($build_0 . $build_1);

        // 账户名称
        $account_name_bytes = array_slice($content, 8);
        $account_name       = [];
        foreach ($account_name_bytes as $k => $v) {
            if ($v != 0) {
                $account_name[] = $v;
            } else {
                break;
            }
        }
        $packdata['account_name'] = int_helper::toStr($account_name);
        $account_name             = $Srp6->BigInteger($packdata['account_name'], 256)->toBytes();
        $next_length              = strlen($account_name) + 8 + 5;

        //client_seed
        $client_seed             = array_slice($content, $next_length, 4);
        $packdata['client_seed'] = $Srp6->BigInteger(int_helper::toStr($client_seed), 256)->toHex();
        $client_seed_Bytes       = $Srp6->BigInteger(int_helper::toStr($client_seed), 256)->toBytes();
        $next_length             = $next_length + 4 + 4 + 4;

        //realm_id
        $realm_id             = array_slice($content, $next_length, 4);
        $packdata['realm_id'] = $Srp6->BigInteger(strrev(int_helper::toStr($realm_id)), 256)->toString();
        $next_length          = $next_length + 4 + 4 + 4;

        //client_hash
        $client_hash             = array_slice($content, $next_length, 20);
        $packdata['client_hash'] = $Srp6->BigInteger(int_helper::toStr($client_hash), 256)->toHex();
        $client_hash_Bytes       = $Srp6->BigInteger(int_helper::toStr($client_hash), 256)->toBytes();

        // 查看账户
        $Account  = new \app\Common\Account();
        $userinfo = $Account->get_account($packdata['account_name']);

        if (!$userinfo) {

            WORLD_LOG('Sent Auth Response (unknown account): ' . $account_name, 'error');

            // 用户不存在
            $AUTH_UNKNOWN_ACCOUNT = $Srp6->BigInteger(OpCode::AUTH_UNKNOWN_ACCOUNT, 16)->toString();
            $data                 = [(int) $AUTH_UNKNOWN_ACCOUNT];

            $packdata = Worldpacket::encrypter(OpCode::SMSG_AUTH_RESPONSE, $data);
            $packdata = array_merge($packdata, $data);

            return $packdata;
        }

        //K
        $sessionkey = $Srp6->BigInteger($userinfo['sessionkey'], 16)->toBytes();

        //保存session
        $connectionCls = new Connection();
        $connectionCls->saveConnector($fd, ['sessionkey' => $sessionkey]);
        $serverseed = $connectionCls->getCache($fd, 'serverseed');

        //计算Hash
        $server_hash       = sha1($account_name . int_helper::toStr([0x00, 0x00, 0x00, 0x00]) . $client_seed_Bytes . $serverseed . $sessionkey);
        $server_hash_Bytes = $Srp6->BigInteger($server_hash, 16)->toBytes();

        //验证
        if ($Srp6->BigInteger($server_hash, 16)->toHex() != $packdata['client_hash']) {
            WORLD_LOG('Verification failed: ' . $account_name, 'error');
            WORLD_LOG('server_hash: ' . $Srp6->BigInteger($server_hash, 16)->toHex(), 'error');
            WORLD_LOG('client_hash: ' . $packdata['client_hash'], 'error');

            // 鉴权失败
            return [0, 0, 4];
        }

        WORLD_LOG('Unpack: ' . json_encode($packdata), 'info');
        WORLD_LOG('server_hash: ' . $Srp6->BigInteger($server_hash, 16)->toHex(), 'warning');
        WORLD_LOG('client_hash: ' . $packdata['client_hash'], 'warning');
        WORLD_LOG('AUTH_OK: Successful verification', 'success');

        // 包体
        $Srp6                 = new Srp6();
        $AUTH_OK              = $Srp6->BigInteger(OpCode::AUTH_OK, 16)->toString();
        $BillingTimeRemaining = [0, 0, 0, 0];
        $BillingPlanFlags     = [0];
        $BillingTimeRested    = [0, 0, 0, 0];
        $expansion            = [(int) $userinfo['expansion']];
        $data                 = array_merge([(int) $AUTH_OK], $BillingTimeRemaining, $BillingPlanFlags, $BillingTimeRested, $expansion);

        //加密
        $encodeheader = Worldpacket::encrypter(OpCode::SMSG_AUTH_RESPONSE, $data, $sessionkey);
        $packdata     = array_merge($encodeheader, $data);

        // //解包
        // $decode = Worldpacket::decrypter($packdata, $sessionkey);
        // var_dump($decode);

        return $packdata;
    }
}
