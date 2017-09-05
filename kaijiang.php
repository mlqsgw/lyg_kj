<?php
require('sdk/include/carrot.orders.php');
require('sdk/include/carrot.message.php');
date_default_timezone_set('PRC');
$order = new CarrotOrders();

$host="192.168.1.41";			//mysql数据库服务器
$userName="longxing";				//mysql数据库用户名
$passwrod="L0ngx!ng123!@#";			//mysql数据库密码

$database = "longxing";			//mysql数据库名

$connection=mysql_connect("$host","$userName","$passwrod");
mysql_query("set names 'utf8'");//编码转化
if (!$connection) {
    die("could not connect to the database.\n" . mysql_error());//诊断连接错误
}
$selectedDb = mysql_select_db($database);//选择数据库

if (!$selectedDb) {
    die("could not to the database\n" . mysql_error());
}


//查询前天以前所有没有开奖的活动
//$time = strtotime(date('Y-m-d',time()-24*60*60*2)); //前天0时时间戳
$time = strtotime(date('Y-m-d H:i:s',time())); //0时时间戳

$lottery_no = "select * from lys_activity where winning_time <$time and (winning_code is null or winning_code = '')";

$result_lottery_no = mysql_query($lottery_no);//执行查询

while($row_lottery_on = mysql_fetch_assoc($result_lottery_no)){

		$activity_id = $row_lottery_on['id'];
		$product_id = $row_lottery_on['product_id'];
		$is_robot = $row_lottery_on['is_robot'];

		if($is_robot == 1){
			doRobotLottery($activity_id,$product_id,$order);
		} else{
			doLottery($activity_id,$product_id,$order);
		}
}

//执行机器人中奖
function doRobotLottery($activity_id,$product_id,$order){
	$activity_id = mysql_real_escape_string($activity_id);//防止SQL注入
	$query = "select * from lys_activity where id = '$activity_id'";//构建查询语句

	$result = mysql_query($query);//执行查询是否有该活动
	if(!$result){
		die("could not to the database\n" . mysql_error());
	}
	while ($row = mysql_fetch_assoc($result)){
		$query_user_robot = "select * from lys_draw where is_robot = 1 and activity_id = '$activity_id' order by Rand() limit 0,1"; //获取抽该活动的机器人用户数据
		$result_user_robot = mysql_query($query_user_robot);//执行查询
		$row_robot = mysql_fetch_assoc($result_user_robot);


		if(!$row_robot){
			doLottery($activity_id,$product_id,$order);	//如果没有机器人用户则执行用户中奖流程
		}else{
			$draw_number = $row_robot['draw_number'];      //机器人该期下的抽奖码
			$winning_code = substr($draw_number,1,8);				//获得中奖码

			//获取中奖人id
			$u_id = $row_robot["u_id"];

			//保存中奖码和中奖人id
			$query_update_activity = "update lys_activity set winning_code = '$winning_code', u_id = '$u_id' where id = '$activity_id'";
			$return = mysql_query($query_update_activity);

			if ($return){
				add_log($u_id,$activity_id,$winning_code);
			} else {
				print_r("活动".$activity_id."执行保存中奖码和中奖人id失败");
			}

		}
	}
}

//执行用户中奖
function doLottery($activity_id,$product_id,$order){
	$activity_id = mysql_real_escape_string($activity_id);//防止SQL注入
	$query = "select * from lys_activity where id = '$activity_id'";//构建查询语句
	$result = mysql_query($query);//执行查询

	while ($row = mysql_fetch_assoc($result)) {

		if (!$row['now_code']){
			print_r("活动id=".$activity_id."该活动没有用户参加\n" . mysql_error());
		}
		//获取当前活动抽奖码
		$now_code = $row["now_code"];

		//生成中奖码
		$winning_code = rand(1, $now_code);

		//自动补全0
		$bit = 8;
		$num_len = strlen($winning_code);  //计算编码长度strlen()
		$zero = "";
		for ($i = $num_len; $i<$bit; $i++) {
			$zero .= "0";
		}
		$winning_code = $zero.$winning_code;

		$robot_join = $row["robot_join"];

		if($robot_join == 1){
			$query = "select * from lys_draw where draw_number like '%".$winning_code."%' and activity_id = '$activity_id'";//构建查询语句
		} else {
			//获取中奖人信息
			$where["winning_code"] = $winning_code;
			$query = "select * from lys_draw where draw_number like '%".$winning_code."%' and activity_id = '$activity_id' and is_robot <> 1";//构建查询语句
		}

		$result_data = mysql_query($query);//执行查询

		if(!$result_data){
			print_r("没有该中奖用户信息" . mysql_error());
		}
		while ($row_data = mysql_fetch_assoc($result_data)) {
			//获取中奖人id
			$u_id = $row_data["u_id"];

			//保存中奖码和中奖人id
			$query = "update lys_activity set winning_code = '$winning_code', u_id = '$u_id' where id = '$activity_id'";
			$return = mysql_query($query);

			if ($return){
				$data = array(
					'user_id' => $u_id,  //用户ID
					'caption' => '0元购第'.$activity_id.'期中奖商品',  //订单标题
					'product' => $product_id,  //商品ID
					'price' => $row['market_price']*100,//商品市场价
					'memo' => '',  //订单备注内容(可选)
					'type' => 0,  //0:普通订单 1:自动发货卡密类(可选，默认0)
					'card_type' => 0,  //卡类类型ID，如果type为1必须提供此参数
                    'status' => 1 //默认值为0， 确认值为1
				);
                if ($row_data["is_robot"] != 1){
                    order_add($data, $order);
                    //todo: 发送短信通知
                    send_sms($u_id,$activity_id);
                }
				add_log($u_id,$activity_id,$winning_code);
			} else {
				print_r("活动".$activity_id."执行保存中奖码和中奖人id失败");
			}
		}
	}
}

//生成订单
function order_add($data,$order){
	$result_order = $order->create($data);
//	print_r($result_order);

}

//发送短信
function send_sms($u_id,$activity_id){
    $data = array("0元抽第".$activity_id."期");  //发送内容为数组，元素与消息模板中的标记一一对应
    $msg = new CarrotMessage();
    echo $msg->send_sms($u_id,3,$data, $msg->get_ip()); //参数1:接收的用户ID; 参数2:消息模板ID; 参数3:内容数组; 参数4:发送者IP（与发送限制有关）
}

//生成文本文件
function add_log($u_id, $activity_id,$winning_code){

	$date = date("Y-m-d",time());
	$log_name = "kaijiang_".$date;
	$find_draw_data = "select * from lys_draw where u_id='$u_id' and activity_id='$activity_id'";

	$find_draw_result = mysql_query($find_draw_data); //执行查找数据

	$draw_data_array = mysql_fetch_assoc($find_draw_result);
	$address = dirname(__FILE__); //获取文件当前地址

	//获取用户信息
	$find_user_data = "select * from lxtx_users where  id = '$u_id'";//构建查询语句
	$find_user_result = mysql_query($find_user_data); //执行查找数据
	$user_data = mysql_fetch_assoc($find_user_result);

	if ($user_data['is_robot'] == 1) {
		$is_robot = "是";
	} else {
		$is_robot = "否";
	}

	$create_time = date("Y-m-d H:i:s", time());
	$draw_data_new = "用户id:".$u_id."用户昵称:".$user_data['nickname']."活动id:".$draw_data_array['activity_id']."抽奖码:".$draw_data_array["draw_number"]."中奖码:".$winning_code."开奖时间:".$create_time."是否是机器人:".$is_robot."\r\n";
	print_r($draw_data_new);
	$myfile = fopen("$address/log/$log_name.txt", "a") or die("Unable to open file!");
	fwrite($myfile, $draw_data_new);
	fclose($myfile);
}

