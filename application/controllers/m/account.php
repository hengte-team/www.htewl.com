<?php
/**
* 现金账户
*/
class Account extends HT_Controller
{
	public function _init()
	{
		$this->load->helper(array('common', 'valid'));
		$this->load->library('alipay/alipaywap', null, 'alipaywap');
		$this->load->model('user_model', 'user');
		$this->load->model('account_log_model', 'account_log');
		$this->load->model('user_deposit_model', 'user_deposit');
		$this->load->model('user_bank_model', 'user_bank');
	}

	/*
	 * 账户
	 */
	public function index()
	{
        $result = $this->user->findByUId($this->uid);
        if ($result->num_rows() > 0) {
            show_404();
        }
		$data['userAccount'] = $result->row(0);
		$this->load->view('m/account/index', $data);
	}

	/**
	 * 充值
	 */
	public function deposit()
	{
		$this->load->view('m/account/deposit');
	}

	/**
	 * 充值提交
	 */
	public function depositPost()
	{
		$amount = $this->input->post('amount');
		$bankId = $this->input->post('pay_bank');
		$uid = 100000;
        $from = 1;
        if ($this->input->get('supplier_uid')) { //存在get参数supplier_uid为收款
            $uid = $this->input->get('supplier_uid');
            $from = 2; //为2时为收款
        }
		if ( $amount <= 0 || !is_numeric($amount) ) {
			$this->error('m/account/deposit', '', '请输入有效的金额');
		}
		if ( $amount >= 1000000 ) {
			$this->error('m/account/deposit', '', '充值金额超出规定范围');
		}
		if (empty($bankId)) {
			$this->error('m/account/deposit', '', '请选择正确的支付方式');
		}
		$result = $this->user->findByUid($uid, 'alias_name,phone,user_type,photo,user_money');

        if ($result->num_rows() <= 0) {
			$this->error('m/account/deposit', '', '商家账户信息有误');
		}
		$user = $result->row(0);

		$dataInsert = array(
			'uid'             => 100000,
			'amount'         => $amount,
			'amount_carry'  => $user->user_money,
			'bank_id'        => $bankId,
            'from'            => $from,
		);
		$insertId = $this->user_deposit->insert($dataInsert);
		if ($insertId) {
			switch ($bankId) {
				case '1':      //支付宝支付
	                $alipayParameter = $this->depositAlipayParameter($insertId, $amount, $user);
	                $this->alipaywap->callAlipayApi($alipayParameter);
	                break;
	            case '201':    //微信支付
                    echo $insertId;
	                $url = $this->config->m_base_url."deposit_wx_call.php?order_id=".$insertId;
	                header("Location:$url");
	                break;
	            default :  //支付出现问题
	                $this->redirect('m/account/fail');
	                break;
        	}
		} else {
			$this->load->view('m/account/fail');
		}
	}

	/**
	 * 充值  支付宝
	 */
	private function depositAlipayParameter($depositId, $amount, $user)
    {
        $parameter = array(
            'out_trade_no'     =>  $depositId,
            'subject'           =>  '订单消费支付',
            'total_amount'     =>  $amount,
            'body'              =>  '消费金额'.$amount.'元',
			'timeout_express' => '1m'
        );
        return $parameter;
    }

	/**
	 * 充值验证
	 */
	private function _validateDeposit($postData)
	{
		if ( $postData['amount'] <= 0 || ! validateFloatNumber($postData['amount']) ) {
			$this->error('m/account/deposit', '', '请输入有效的金额');
		}

		if ( $postData['amount'] >= 1000000 ) {
			$this->error('m/account/deposit', '', '充值金额超出规定范围');
		}

		if (empty($postData['pay_bank'])) {
			$this->error('m/account/deposit', '', '请选择银行并填写银行卡号');
		}
		return TRUE;
	}

	/**
	 * 收支明细
	 */
	public function record()
	{
		$count = 20;
		$total = $this->account_log->amountLogTotal(array('uid' => $this->uid));
		if ( $total > 0 )
		{
			$data['amountList'] = $this->account_log->amountLogList(array('uid' => $this->uid), 0, $count);
		}
		$data['more'] = $total > $count ? true : false;
		$data['levelType'] = $this->levelType();
		$this->load->view('home/account/record', $data);
	}

	/**
	 * ajax 收支详细
	 */
	public function ajaxRecord()
	{
		$page = $this->input->post('page');
		$type = $this->input->post('type');
		$num = 20;
		$params = array('uid' => $this->uid, 'type' => $type);
		$total = $this->account_log->amountLogTotal($params);
		$data['amountList'] = $this->account_log->amountLogList($params, $num * $page, $num);
		$data['levelType'] = $this->levelType();
		echo json_encode(
				array(
					'status'  => ($total >  ($page + 1) * $num) ? true : false,
					'html'    => $this->load->view('home/account/ajaxRecord', $data, true),
					'possess' => ($total == 0) ? false : true
				)
			);
		exit;
	}

	/**
	 * 提现
	 */
	public function withdraw()
	{
//		$userBank = $this->user_bank->findByUid($this->uid);
//		if ($userBank->num_rows() == 0) {
//			$this->redirect('home/card/bankCard');//请先绑定银行卡
//		}
//		$data['userBank'] = $userBank->result();
//		$data['userAccount'] = $this->user_account->findByUid($this->uid)->row();
		$this->load->view('m/account/withdraw', $data=array());
	}

	/**
	 * 提现提交
	 */
	public function ajaxWithdrawPost()
	{
		$postData = $this->input->post();
		$bank = $this->_validateWithdraw($postData);

		$userAccount = $this->user_account->findByUid($this->uid)->row();
		if ( bccomp($userAccount->amount_carry, $postData['amount'], 2) < 0 )
		{
			$this->jsonMessage('提现金额超出限额');
		}
		$postData['uid'] = $this->uid;
		
		$this->db->trans_begin();
		$postData['amount_carry'] = $userAccount->amount_carry;
		$postData['user_name'] = $this->userName;
		$withdraw = $this->withdraw->insertWithdraw($postData, $bank);
		$user = $this->user_account->userWithdraw($this->uid, $postData['amount']);
		if ( ! $withdraw OR ! $user OR $this->db->trans_status() === FALSE )
		{
			$this->db->trans_rollback();
			$this->jsonMessage('申请提现失败');
		}
		else
		{
			$this->db->trans_commit();
			$this->jsonMessage('', site_url('home/account/index'));
		}
	}

	/**
	 * 提现验证
	 */
	private function _validateWithdraw($postData)
	{
		$this->_validatePaymentPassword();
		if ( $postData['amount'] <= 0 || ! validateFloatNumber($postData['amount']) ) 
		{
			$this->jsonMessage('请输入有效的金额');
		}

		if ( empty($postData['user_bank_id']) )
		{
			$this->jsonMessage('请选择银行并填写银行卡号');
		}

		$bank = $this->user_bank->findById($postData['user_bank_id']);
		if ( 0 == $bank->num_rows() )
		{
			$this->jsonMessage('请选择银行并填写银行卡号');
		}
		return $bank->row();
	}

	/**
	 * 验证支付密码
	 */
	private function _validatePaymentPassword()
	{
		$password = $this->input->post('payment_password');
		$user = $this->user->findById($this->uid)->row();
		if ( $user->pw != md5($password) )
		{
			$this->jsonMessage('支付密码错误');
		}
	}

	/**
	 * 支付密码加密
	 */
	private function _encryptPaymentPassword($password)
	{
		$head = substr($password, 0, 5);
		$tail = substr($password, 5);
		return sha1(base64_encode($tail) . md5($password));
	}

	/**
	 * 提现记录
	 */
	public function withdrawRecord()
	{
		$count = 20;
		$total = $this->withdraw->withdrawTotal(array('uid' => $this->uid));
		if ( $total > 0 )
		{
			$data['withdrawList'] = $this->withdraw->withdrawList(array('uid' => $this->uid), 0, $count);
		}
		$data['more'] = $total > $count ? true : false;
		$this->load->view('home/account/withdrawRecord', $data);
	}

	/**
	 * ajax 刷新提现记录列表
	 */
	public function ajaxWithdrawRecord()
	{
		$page = $this->input->post('page');
		$num = 20;
		$total = $this->withdraw->withdrawTotal(array('uid' => $this->uid));
		$data['withdrawList'] = $this->withdraw->withdrawList(array('uid' => $this->uid), $num * $page, $num);

		echo json_encode(
				array(
					'status' => ($total >  ($page + 1) * $num) ? true : false,
					'html' => $this->load->view('home/account/ajaxWithdrawRecord', $data, true)
				)
			);
		exit;
	}

	/**
	 * 我的金币
	 */
	public function myBouns()
	{
		$data['userAccount'] = $this->user_account->findByUid($this->uid)->row();
		$this->load->view('home/account/myBouns', $data);
	}

	/**
	 * 成功
	 */
	public function complete()
	{
		$data = array();
		$this->load->view('home/account/success', $data);
	}

	/**
	 * 失败
	 */
	public function fail()
	{
		$data = array();
		$this->load->view('home/account/fail', $data);
	}
}