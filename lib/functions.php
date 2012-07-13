<?php

/**
 *  Functions for zpanelx Auto-sign-up
 *  
 *  @package    Zpanelx Auto-sign-up
 *  @author     Tony Maclennan & Martin Kollerup
 *  @license    http://opensource.org/licenses/gpl-3.0.html
 */

class zpanelx{
	static $newUserError;
	function sendemail($emailto, $emailsubject, $emailbody, $fromEmailName = "KMWeb.dk") {

		//get from email
		$fromemail = self::getConfig('fromemail');
		$to = $emailto;
		$subject = $emailsubject;
		$message = $emailbody;
		$headers  = 'MIME-Version: 1.0' . "\r\n";
		$headers .= 'Content-type: text/html; charset=iso-8859-1' . "\r\n";
		$headers .= "From: " . $fromEmailName ;
		$worked = mail($to,$subject,$message,$headers,'-f '.$fromemail);

		if($worked) {
			return true;
		} 
		else {
			return false;
		}
	} 

	/**
		* Send email when we have accepted the payment.
		* @author Tony
		* @return true or false 
	*/
	function sendWelcomeMail($id){

		$db = $db->getConnection();
		$stmt = $db->prepare("SELECT * FROM x_accounts WHERE ac_id_pk=?");
		$stmt->execute(array($id));
		$row = $stmt->fetch();

		$body = file_get_contents('../templates/emails/user_welcome-email.html');
		$body = str_replace('$username',$row['ac_user_vc'],$body);
		$body = str_replace('$cpurl',self::getCongfig('zpanel_url'),$body);
		$body = str_replace('$ns1',self::getConfig('ns1'),$body);				
		$body = str_replace('$ns2',self::getConfig('ns2'),$body);
		$toemail = $row['ac_email_vc'];
		
		if(self::sendemail($toemail,"Welcome",$body)){
			return true;
		}
		else{
			return false;
		}
	}

	/**
	    * Generate a password for the payer.
	    * There is fallback to mt:rand() if openssl not is supported
	    * @link http://www.php.net/manual/en/function.openssl-random-pseudo-bytes.php#96812
	    * @return password
	*/

	function generatePassword($length = 8) {
	        if(function_exists('openssl_random_pseudo_bytes')) {
	            $password = base64_encode(openssl_random_pseudo_bytes($length, $strong));
	            if($strong == TRUE)
	                return substr($password, 0, $length); //base64 is about 33% longer, so we need to truncate the result
	        }
	        //fall back to mt_rand
	        $characters = '0123456789';
	        $characters .= 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz/&'; 
	        $charactersLength = strlen($characters)-1;
	        $password = '';

	        //select some random characters
	        for ($i = 0; $i < $length; $i++) {
	            $password .= $characters[mt_rand(0, $charactersLength)];
	        }        
	        return $password;
	}


	/**
	    * Generate the token the payment should be specified with.
	    * @link http://www.php.net/manual/en/function.openssl-random-pseudo-bytes.php#96812
	    * @return token
	*/

	function generateToken($length = 24) {

	        $characters = '0123456789';
	        $characters .= 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz'; 
	        $charactersLength = strlen($characters)-1;
	        $token = '';

	        //select some random characters
	        for ($i = 0; $i < $length; $i++) {
	            $token .= $characters[mt_rand(0, $charactersLength)];
	        }        
	        return $token;
	}

	/**
		* Check if the email is true | Uuuuh, this is danish! ;)
		* @credit http://phptips.dk/check_email_adresse_inklusive_dns_mx_record_lookup.tip
		* @return true or false 1
	*/

	function checkEmail($email)
	{
		$isValid = true;                 
		$atIndex = strrpos($email, "@"); 

		if (is_bool($atIndex) && !$atIndex)
		{
			$isValid = false;
		} 
		else {
			$domain = substr($email, $atIndex+1); 
			$local = substr($email, 0, $atIndex); 
			$localLen = strlen($local); 
			$domainLen = strlen($domain);
			
			if ($localLen < 1 || $localLen > 64)
			{
				$isValid = false;
			}
	       
	       else if ($domainLen < 1 || $domainLen > 255)
			{
				$isValid = false;
			}
			else if ($local[0] == '.' || $local[$localLen-1] == '.')
			{
				$isValid = false;
			}
			else if (preg_match('/\\.\\./', $local))
			{
				$isValid = false;
			}
			else if (!preg_match('/^[A-Za-z0-9-.]+$/', $domain))
			{
				$isValid = false;
			}
			else if (preg_match('/\\.\\./', $domain))
			{ 
				$isValid = false;                  
			}
			else if(!preg_match('/^(\\\\.|[A-Za-z0-9!#%&`_=\\/$\'*+?^{}|~.-])+$/',str_replace("\\\\","",$local)))
			{                              
				if (!preg_match('/^"(\\\\"|[^"])+"$/',str_replace("\\\\","",$local)))
				{
	            	$isValid = false;
				}	
			}
			//check with mx_recors
			if ($isValid && !getmxrr($domain,$mxhosts))
			{
				$isValid = false;
			}
	   }
	   return $isValid;
	}

	/**
	* Lets add the user to the database and make the folders. WUHU!!
	* @
	*/
	function newUser($payperiod, $packageid, $token, $password, $username, $email, $fullname, $adress, $postcode, $telephone){

		$db = db::getConnection();
		$stmt = $db->prepare("SELECT * FROM x_packages WHERE pk_id_pk= ?");
			
		if($stmt->execute(array($packageid))){
			$row = $stmt->fetch();
			switch($payperiod){
				case '1':
					$selectedpackageprice = $row['pk_price_pm'];
					$hostingTime = "3"; //month
				break;
				case '2' :
					$selectedpackageprice = $row['pk_price_pq'];
					$hostingTime = "6"; //month
				break;
				case'3':
					$selectedpackageprice = $row['pk_price_py'];
					$hostingTime = "12"; //month
				break;
			}
		}

		$todaydate = date("Y-m-d");// current date
		$newdate = strtotime(date("Y-m-d", strtotime($todaydate)) . $hostingTime." month");
		$newdate = date('Y-m-d', $newdate);

		//add user to table
		$stmt = $db->prepare("INSERT INTO x_accounts (ac_user_vc, ac_pass_vc, ac_email_vc, ac_reseller_fk, ac_package_fk, ac_group_fk, ac_usertheme_vc, ac_usercss_vc,ac_enabled_in,ac_price_pm,ac_invoice_nextdue,ac_invoice_period) VALUES (:username, :password,:email,'1',:packageid,'3','zpanelx','default','0',:selectedpackageprice,:newdate,:payperiod)");
		$query = array(':username'=>$username, ':password'=>md5($password),':email'=>$email, ':packageid'=>$packageid, ':selectedpackageprice'=>$selectedpackageprice, ':newdate'=>$newdate,':payperiod'=>$payperiod);
		
		if(!$stmt->execute($query)){
			echo $stmt->errorInfo();
		}
		$user_id = $db->lastInsertId();

		//add to profile
		$stmt = $db->prepare("INSERT INTO x_profiles (ud_user_fk, ud_fullname_vc, ud_language_vc, ud_group_fk, ud_package_fk, ud_address_tx, ud_postcode_vc, ud_phone_vc, ud_created_ts) VALUES (:user_id, :username, 'en', '0', '0', :adress, :postcode, :telephone, '')");
		$query = array(':user_id'=>$user_id, ':username'=>$username,':adress'=>$adress, ':postcode'=>$postcode, ':telephone'=>$telephone);
		
		if(!$stmt->execute($query)){
			echo $stmt->errorInfo();
		}

		//add to next mounth bandwidth
		$stmt = $db->prepare("INSERT INTO x_bandwidth (bd_acc_fk, bd_month_in, bd_transamount_bi, bd_diskamount_bi) VALUES (" . $user_id . "," . date("Ym", time()) . ", 0, 0)");
		$stmt->execute();

		//add to invoice
		$stmt = $db->prepare("INSERT INTO x_invoice(inv_user, inv_amount, inv_description, inv_duedate, inv_createddate, inv_act, token) VALUES (:user_id,:selectedpackageprice,'Initial Signup',:todaydate,:todaydate,'1',:token)");
		$query = array(':user_id'=>$user_id, ':selectedpackageprice'=>$selectedpackageprice,':todaydate'=>$todaydate, ':todaydate'=>$todaydate, ':token'=>$token);
		
		if(!$stmt->execute($query)){
			echo $stmt->errorInfo();
		}

		//close pdo
		$db = null;

		$emailtext = file_get_contents("templates/emails/user_reg.html");
		$emailtext = str_replace('$fullname',$fullname,$emailtext);
		$emailtext = str_replace('$pathto',self::getConfig('billing_url'),$emailtext);
		$emailtext = str_replace('$invid',$token,$emailtext);
		$emailtext = str_replace('$userid',$username,$emailtext);
		$emailtext = str_replace('$password',$password,$emailtext);

		//send a email to the user that they have been created.
		self::sendemail($email, "New Account Created", $emailtext);

		//redirect to the payment page
		header( 'Location: pay.php?id=' . $token );
	}//end new user

	/**
	* Create the user through zpanelx API.m
	* You should have installed reseller_billing module in zpanel
	* Please enable debug when testing.
	* @return redirect on succes | false set $newusererror
	*/

	function newUser2($payperiod, $packageid, $token, $password, $username, $email, $fullname, $address, $postcode, $telephone){
		require 'xmwsclient.class.php';

		$error = null;
		$db = db::getConnection();
		$stmt = $db->prepare("SELECT * FROM x_packages WHERE pk_id_pk= ?");
			
		if($stmt->execute(array($packageid))){
			$row = $stmt->fetch();
			switch($payperiod){
				case '1':
					$selectedpackageprice = $row['pk_price_pm'];
					$hostingTime = "3"; //month
				break;
				case '2' :
					$selectedpackageprice = $row['pk_price_pq'];
					$hostingTime = "6"; //month
				break;
				case'3':
					$selectedpackageprice = $row['pk_price_py'];
					$hostingTime = "12"; //month
				break;
			}
		}

		$xmws = new xmwsclient();
		$xmws->InitRequest(self::getConfig('zpanel_url'), 'manage_clients', 'CreateClient', self::getConfig('api'));
		$xmws->SetRequestData('<resellerid>'.self::getConfig('reseller_id').'</resellerid>
                        <username>'.$username.'</username>
                        <packageid>'.$packageid.'</packageid>
                        <groupid>'.self::getConfig('groupid').'</groupid>
                        <fullname>'.$fullname.'</fullname>
                        <email>'.$email.'</email>
                        <postcode>'.$postcode.'</postcode>
                        <address>'.$address.'</address>
                        <phone>'.$telephone.'</phone>
                        <password>867hhvlk</password>
                        <sendemail>0</sendemail>
                        <emailsubject>0</emailsubject>
                        <emailbody>0</emailbody>');

		$returnClient = $xmws->XMLDataToArray($xmws->Request($xmws->BuildRequest()), 0);

		$userId = $returnClient['xmws']['content']['uid'];
		$todaydate = date("Y-m-d");// current date
		$newdate = strtotime(date("Y-m-d", strtotime($todaydate)) . $hostingTime." month");
		$newdate = date('Y-m-d', $newdate);
		
		if($returnClient['xmws']['content']['created'] == 'true'){
			$client = new xmwsclient();
			$client->InitRequest(self::getConfig('zpanel_url'), 'manage_clients', 'DisableClient', self::getConfig('api'));
			$client->SetRequestData('<uid>'.$userId.'</uid>');
			$returnDisable = $client->XMLDataToArray($client->Request($client->BuildRequest()), 0);

			if($returnDisable['xmws']['content']['disabled'] == 'true'){
				$xmws = new xmwsclient();
				$xmws->InitRequest(self::getConfig('zpanel_url'), 'reseller_billing', 'CreateInvoice', self::getConfig('api'));
				$xmws->SetRequestData('<user_id>'.$userId.'</user_id>
		                        		<selectedpackageprice>'.$selectedpackageprice.'</selectedpackageprice>
		                        		<token>'.$token.'</token>
		                        		<price>'.$selectedpackageprice.'</price>
		                        		<invoice_nextdue>'.$newdate.'</invoice_nextdue>
		                        		<invoice_period>'.$payperiod.'</invoice_period>');
				$returnInvoice = $xmws->XMLDataToArray($xmws->Request($xmws->BuildRequest()), 0);
				
				if($returnInvoice['xmws']['content'] == "1"){
					$emailtext = file_get_contents("templates/emails/user_reg.html");
					$emailtext = str_replace('$fullname',$fullname,$emailtext);
					$emailtext = str_replace('$pathto',self::getConfig('billing_url'),$emailtext);
					$emailtext = str_replace('$invid',$token,$emailtext);
					$emailtext = str_replace('$userid',$username,$emailtext);
					$emailtext = str_replace('$password',$password,$emailtext);

					//send a email to the user that they have been created.
					self::sendemail($email, "New Account Created", $emailtext);

					//redirect to the payment page
					header( 'Location: pay.php?id=' . $token );
				}else {
						self::sendemail(self::getConfig('email_paypal_error'), "Error disabling account", "The invoice have not been created for user: ".$username );
							if (self::getConfig('DEBUG')){echo "error invoice";}
						self::$newUserError = true;
				}
			} else {
					self::sendemail(self::getConfig('email_paypal_error'), "Error disabling account", "A new account have been created but not disabled. The invoice have for this reason not been created and the user cannot pay. User: ".$username);
						if (self::getConfig('DEBUG')){echo "error disabling ".$userId;}
					self::$newUserError = true;
				}
		} else {
				self::sendemail(self::getConfig('email_paypal_error'), "Error creating account", "A new account have tried to be created, but failed");
					if (self::getConfig('DEBUG')){echo "error creating";}
				self::$newUserError = true;
				}
		if(self::getConfig('DEBUG')){
			print('<pre>');
			print_r($returnClient);
			print_r($returnDisable);
			print_r($xmws->BuildRequest());
			print_r($client->BuildRequest());
			print('</pre>');
		}
	}

	/**
	 * Get the config values
	 * @copyright Copyright (c)2009-2012 Nicholas K. Dionysopoulos
	*/
	public static function getConfig( $key, $default = null )
	{
		if( !class_exists('zConfig') )
		{
			require_once('config.php');
		}
		$config = new zConfig;
		$class_vars = get_class_vars('zConfig');
		if( array_key_exists($key, $class_vars) ){
			return $class_vars[$key];
		}
		else{
			return $default;
		}
	}
}

?>