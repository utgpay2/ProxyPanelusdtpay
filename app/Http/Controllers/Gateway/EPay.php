<?php

namespace App\Http\Controllers\Gateway;

use Auth;
use Http;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Log;
use Response;

class EPay extends AbstractPayment
{
    public function purchase(Request $request): JsonResponse
    {
        $payment = $this->creatNewPayment(Auth::id(), $request->input('id'), $request->input('amount'));


		$params = [
            'merchantId' => sysConfig('epay_mch_id'),
            'outTradeNo' => $payment->trade_no,
            'subject' => $payment->trade_no,
            'totalAmount' => $payment->amount,
            'attach' => $payment->amount,
            'body' => $payment->trade_no,
            'coinName' => 'USDT-TRC20',
            'notifyUrl' => route('payment.notify', ['method' => 'epay']),
            'timestamp' => $this->msectime(),
            'nonceStr' => $this->getNonceStr(16)
        ];
		$mysign = self::GetSign(sysConfig('epay_key'), $params);
		$ret_raw = self::_curlPost(sysConfig('epay_url'), $params,$mysign,1);
        $ret = @json_decode($ret_raw, true);
		if(!empty($ret['data']['paymentUrl'])){
			$payment->update(['url' => $ret['data']['paymentUrl']]);
        	return Response::json(['status' => 'success', 'url' => $ret['data']['paymentUrl'], 'message' => '创建订单成功!']);
		}

        
    }
	/**
     * 设置签名，详见签名生成算法
     * @param $secret
     * @param $params
     * @return array
     */
    public function GetSign($secret, $params)
    {
        $p=ksort($params);
        reset($params);

		if ($p) {
			$str = '';
			foreach ($params as $k => $val) {
				$str .= $k . '=' .  $val . '&';
			}
			$strs = rtrim($str, '&');
		}
		$strs .='&key='.$secret;

        $signature = md5($strs);

        //$params['sign'] = base64_encode($signature);
        return $signature;
    }
    public function msectime() {
		list($msec, $sec) = explode(' ', microtime());
		$msectime = (float)sprintf('%.0f', (floatval($msec) + floatval($sec)) * 1000);
		return $msectime;
    }
    /**
     * 返回随机字符串
     * @param int $length
     * @return string
     */
    public static function getNonceStr($length = 32)
    {
        $chars = "abcdefghijklmnopqrstuvwxyz0123456789";
        $str = "";
        for ($i = 0; $i < $length; $i++) {
            $str .= substr($chars, mt_rand(0, strlen($chars) - 1), 1);
        }
        return $str;
    }

    private function _curlPost($url,$params=false,$signature,$ispost=0){
        
		$ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 300); //设置超时
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE); // https请求 不验证证书和hosts
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, FALSE);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
        curl_setopt(
            $ch, CURLOPT_HTTPHEADER, array('token:'.$signature)
        );
        $result = curl_exec($ch);
        curl_close($ch);
        return $result;
    }
    public function notify(Request $request): void
    {
	    $content = file_get_contents('php://input');
            //$content = file_get_contents('php://input', 'r');
        $json_param = json_decode($content, true); //convert JSON into array
        
        if(!empty($json_param)){
    		$coinPay_sign = $json_param['sign'];
    		unset($json_param['sign']);
    		unset($json_param['notifyId']);
    		
    		$sign = self::GetSign(sysConfig('epay_key'), $json_param);
    		if ($sign !== $coinPay_sign) {
    			Log::info('易支付：交易失败');
    		}else{
    		    
    			$out_trade_no = $json_param['outTradeNo'];
    			if ($this->paymentReceived($out_trade_no)) {
                    exit('SUCCESS');
                }
    		}
        }
        exit('FAIL');
    }

    public function queryInfo(): JsonResponse
    {
        $response = Http::get(sysConfig('epay_url').'api.php', [
            'act' => 'query',
            'pid' => sysConfig('epay_mch_id'),
            'key' => sysConfig('epay_key'),
        ]);

        if ($response->ok()) {
            return Response::json(['status' => 'success', 'data' => $response->json()]);
        }

        return Response::json(['status' => 'fail', 'message' => '获取失败！请检查配置信息']);
    }
}
