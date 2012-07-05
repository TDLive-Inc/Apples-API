
<!-- saved from url=(0057)http://git.tdlive.org/apples-api/master/blob?path=api.php -->
<html><head><meta http-equiv="Content-Type" content="text/html; charset=ISO-8859-1"></head><body><pre style="word-wrap: break-word; white-space: pre-wrap;">#!/usr/bin/php
&lt;?php

/* I can't take credit for Kelpie, written by dhotson.
   It's only SLIGHTLY modified to fit into one file.

   See http://getkelpie.com OR http://github.com/dhotson.*/

/**
 * A simple single-threaded tcp server for running Kelpie apps
 */
class Kelpie_Server
{
	private $_host;
	private $_port;

	public function __construct($host = '0.0.0.0', $port = 8000)
	{
		echo "...done!\n";
		$this-&gt;_host = $host;
		$this-&gt;_port = $port;
	}

	/**
	 * Start serving requests to the application
	 * @param Kelpie_Application
	 */
	public function start($app)
	{
		if (!($socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP)))
		{
			throw new Kelpie_Server_Exception(socket_strerror(socket_last_error()));
		}

		if (!socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1))
		{
			throw new Kelpie_Server_Exception(socket_strerror(socket_last_error()));
		}

		if (!socket_bind($socket, $this-&gt;_host, $this-&gt;_port))
		{
			throw new Kelpie_Server_Exception(socket_strerror(socket_last_error()));
		}

		if (!socket_listen($socket))
		{
			throw new Kelpie_Server_Exception(socket_strerror(socket_last_error()));
		}

		while ($client = socket_accept($socket))
		{
			$data = '';
			$nparsed = 0;

			$h = new HttpParser();

			while ($d = socket_read($client, 1024 * 1024 * 1024))
			{
				$data .= $d;
				$nparsed = $h-&gt;execute($data, $nparsed);

				if ($h-&gt;isFinished())
				{
					break;
				}

				if ($h-&gt;hasError())
				{
					socket_close($client);
					continue 2; // Skip to accept next connection..
				}
			}

			$env = $h-&gt;getEnvironment();

			global $argv;
			$env['SERVER_NAME'] = $this-&gt;_host;
			$env['SERVER_PORT'] = $this-&gt;_port;
			$env['SCRIPT_FILENAME'] = $argv[0];
			socket_getpeername($client, $address);
			$env['REMOTE_ADDR'] = $address;

			list($status, $headers, $body) = $app-&gt;call($env);

			$response = new Kelpie_Server_Response();
			$response-&gt;setStatus($status);
			$response-&gt;setHeaders($headers);
			$response-&gt;setBody($body);

			foreach ($response as $chunk)
			{
				$len = strlen($chunk);
				$offset = 0;
				while ($offset &lt; $len)
				{
					if (false === ($n = @socket_write($client, substr($chunk, $offset), $len-$offset)))
						break 2;

					$offset += $n;
				}
			}

			socket_close($client);
		}
	}
}

class Kelpie_Server_Exception extends Exception
{
}

/**
 * @author dennis@99designs.com
 */
class Kelpie_Server_Headers implements ArrayAccess
{
	private static $HEADER_FORMAT = "%s: %s\r\n";
	private static $ALLOWED_DUPLICATES = array('Set-Cookie','Set-Cookie2','Warning','WWW-Authenticate');

	public function __construct()
	{
		$this-&gt;_sent = array();
		$this-&gt;_out = array();
	}

	public function offsetExists($key)
	{
		return isset($this-&gt;_sent[$key]);
	}

	public function offsetGet($key)
	{
		return $this-&gt;_sent[$key];
	}

	public function offsetSet($key, $value)
	{
		if (!isset($this-&gt;_sent[$key]) || in_array($key, self::$ALLOWED_DUPLICATES))
		{
			$this-&gt;_sent[$key] = true;

			if (!isset($value))
				return;
			elseif ($value instanceof DateTime)
				$value = $value-&gt;format(DateTime::RFC1123);
			else
				$value = "$value";

			$this-&gt;_out []= sprintf(self::$HEADER_FORMAT, $key, $value);
		}
		return $this-&gt;_sent[$key] = $value;
	}

	public function offsetUnset($key)
	{
		unset($this-&gt;_sent[$key]);
	}

	public function __toString()
	{
		return implode($this-&gt;_out);
	}
}

class Kelpie_Server_Response
	implements IteratorAggregate
{
	const CONNECTION = 'Connection';
	const CLOSE = 'close';
	const KEEP_ALIVE = 'keep-alive';
	const SERVER = 'Server';
	const CONTENT_LENGTH = 'Content-Length';

	private $_status;
	private $_body;
	private $_headers;
	private $_persistent;

	public function __construct()
	{
		$this-&gt;_headers = new Kelpie_Server_Headers();
		$this-&gt;_status = 200;
		$this-&gt;_persistent = false;
	}

	/**
	 * String representation of the headers
	 * to be sent in the response.
	 */
	public function headersOutput()
	{
		// Set default headers
		$this-&gt;_headers[self::CONNECTION] = $this-&gt;isPersistent()
			? self::KEEP_ALIVE
			: self::CLOSE
			;

		return "$this-&gt;_headers";
	}

	/**
	 * Top header of the response,
	 * containing the status code and response headers.
	 */
	public function head()
	{
		return sprintf(
			"HTTP/1.1 %s %s\r\n%s\r\n",
			$this-&gt;_status,
			self::$HTTP_STATUS_CODES[$this-&gt;_status],
			$this-&gt;headersOutput()
		);
	}

	public function setStatus($status)
	{
		$this-&gt;_status = $status;
		return $this;
	}

	public function setBody($body)
	{
		$this-&gt;_body = $body;
		return $this;
	}

	/**
	 * array($key =&gt; $value)
	 * $key must be a string
	 * $value can be either an array, or a string separated by newlines
	 */
	public function setHeaders($headers)
	{
		foreach($headers as $key =&gt; $value)
		{
			if (is_string($value))
				$value = explode("\n", $value);

			foreach ($value as $v)
			{
				$this-&gt;_headers[$key] = $v;
			}
		}
	}

	public function getIterator()
	{
		$it = new AppendIterator();

		$it-&gt;append(new ArrayIterator(array($this-&gt;head())));

		if (is_string($this-&gt;_body))
			$it-&gt;append(new ArrayIterator(array($this-&gt;_body)));
		elseif (is_array($this-&gt;_body))
			$it-&gt;append(new ArrayIterator($this-&gt;_body));
		elseif ($this-&gt;_body instanceof Iterator)
			$it-&gt;append($this-&gt;_body);

		return $it;
	}

	public function persistent()
	{
		$this-&gt;_persistent = true;
	}

	public function isPersistent()
	{
		return $this-&gt;_persistent &amp;&amp; isset($this-&gt;_headers[self::CONTENT_LENGTH]);
	}

	private static $HTTP_STATUS_CODES = array(
		100  =&gt; 'Continue',
		101  =&gt; 'Switching Protocols',
		200  =&gt; 'OK',
		201  =&gt; 'Created',
		202  =&gt; 'Accepted',
		203  =&gt; 'Non-Authoritative Information',
		204  =&gt; 'No Content',
		205  =&gt; 'Reset Content',
		206  =&gt; 'Partial Content',
		300  =&gt; 'Multiple Choices',
		301  =&gt; 'Moved Permanently',
		302  =&gt; 'Moved Temporarily',
		303  =&gt; 'See Other',
		304  =&gt; 'Not Modified',
		305  =&gt; 'Use Proxy',
		400  =&gt; 'Bad Request',
		401  =&gt; 'Unauthorized',
		402  =&gt; 'Payment Required',
		403  =&gt; 'Forbidden',
		404  =&gt; 'Not Found',
		405  =&gt; 'Method Not Allowed',
		406  =&gt; 'Not Acceptable',
		407  =&gt; 'Proxy Authentication Required',
		408  =&gt; 'Request Time-out',
		409  =&gt; 'Conflict',
		410  =&gt; 'Gone',
		411  =&gt; 'Length Required',
		412  =&gt; 'Precondition Failed',
		413  =&gt; 'Request Entity Too Large',
		414  =&gt; 'Request-URI Too Large',
		415  =&gt; 'Unsupported Media Type',
		500  =&gt; 'Internal Server Error',
		501  =&gt; 'Not Implemented',
		502  =&gt; 'Bad Gateway',
		503  =&gt; 'Service Unavailable',
		504  =&gt; 'Gateway Time-out',
		505  =&gt; 'HTTP Version not supported'
	);
}

/* End Kelpie */

class TDLive_General_Utils {

	function translateuri($string){
		$requri=explode("/", $string);
		return $requri;
	}
}
class HelloWorldApp
{
	private function cardsdb(){
		return array("cards" =&gt; array("Cheese", "Raffles", "My Face", "TDLive.org Inc."), "descs" =&gt; array("Spray-can deliciousness.", '"Place a ticket here to win a fabulous prize!" Yeah, right.', "So beautiful.", 'Web design and development company based in the Northeast. "Its not that I care too much, its that you care too little." ~TDLive'));
	}
	private function checkToken($ip, $user, $token){
		if(! $tokencontents=file_get_contents($ip . ".token")){
			return false;
		}
		$tokencontents=explode("/", $tokencontents);
		if($tokencontents[1] == $user &amp;&amp; $tokencontents[0] == $token){
			return true;
		}
		else{
			return false;
		}
	}
	public function put($code, $json){
		return array(
			$code,
			array("Content-Type" =&gt; "text/json"),
			array(json_encode($json))
		);
	}
	public function call($env)
	{
		/*$genutils=new TDLive_General_Utils;
		$varget=$genutils-&gt;determinateGETvars($env['QUERY_STRING']); */
		echo "Accepted connection from " . gethostbyaddr($env["REMOTE_ADDR"]) . ". ";
		$me=new TDLive_General_Utils;
		$path=$me-&gt;translateuri($env["REQUEST_URI"]);
		if($path[1] == "call"){
			if($path[2] == "alive"){
				echo "Requested alive state. Returned a 200 OK.\n";
				$return=$this-&gt;put(200, array("alive" =&gt; "true"));
			}
			elseif($path[2] == "token"){
				echo "Wants token. ";
				$ip=$env['REMOTE_ADDR'];
				if(! @isset($path[3])){
					echo "No username! Returned a 400 Bad Request.\n";
					return $this-&gt;put(400, array("error" =&gt; "user"));
				}
				if(file_exists($ip . ".token")) {
					echo "Token was issued. Returned 400 Bad Request.\n";
					return $this-&gt;put(400, array("error" =&gt; "alreadyissued"));
				}
				$letters=array("a", "b", "c", "d", "e", "f", "g", "h", "i", "j", "k", "l", "m", "n", "o", "p", "q", "r", "s", "t", "u", "v", "w", "x", "y", "z", "0", "1", "2", "3", "4", "5", "6", "7", "8", "9");
				$token="";
				$n=0;
				while($n &lt; 30){
					$letteradd=$letters[rand(0, 35)];
					$token=$token . $letteradd;
					$n++;
				}
				file_put_contents($ip . ".token", $token . "/" . $path[3]);
				echo "Returned token " . $token . "\n";
				$return=$this-&gt;put(200, array("token" =&gt; $token));
			}
			elseif($path[2] == "card"){
				echo "Requested a new card. ";
				$cards=$this-&gt;cardsdb();
				$card=rand(0, count($cards['cards'])-1);
				$json=array("card" =&gt; $cards['cards'][$card], "desc" =&gt; $cards['descs'][$card], "id" =&gt; $card);
				echo $json["card"] . " issued.";
				$return=$this-&gt;put(200, $json);
			}
			elseif($path[2] == "playcard"){
				echo "Trying to play a card.";
				if(! @isset($path[3])){
					echo "Request denied. No token specified.\n";
					return $this-&gt;put(400, array("error" =&gt; "notoken"));
				}
				elseif(! @isset($path[5])){
					echo "Request denied. No card specified.\n";
					return $this-&gt;put(400, array("error" =&gt; "nocard"));
				}
				elseif(! @isset($path[4])){
					echo "Request denied. No user name specified.\n";
					return $this-&gt;put(400, array("error" =&gt; "nouser"));
				}
				if(! $this-&gt;checkToken($env["REMOTE_ADDR"], $path[3], $path[4])){
					echo "Request denied. Invalid combo.\n";
					return $this-&gt;put(400, array("error" =&gt; "invalidtoken"));
				}
				$user=$path[4];
				$cards=$this-&gt;cardsdb();
				if(! @isset($cards[$path[5]])){
					echo "Request denied. Card doesn't exist.\n";
					return $this-&gt;put(400, array("error" =&gt; "carddoesntexist"));
				}
				if(! is_dir("judgecards")){
					if(! mkdir("judgecards")){
						echo "Request denied. Cannot create judgecards directory.";
						return $this-&gt;put(500, array("error" =&gt; "nodircreate"));
					}
				}
				if(file_exists("judgecards/$user")){
					echo "Request denied. Card already played.";
					return $this-&gt;put(400, array("error" =&gt; "alreadyplayed"));
				}
				file_put_contents($path[5], "judgecards/$user");
				return $this-&gt;put(200, array("played" =&gt; true));
				}
			elseif($path[2] == "destroytoken"){
				echo "Wants to destroy token. ";
				$ip=$env['REMOTE_ADDR'];
				if(! file_exists($ip . ".token")){
					echo "Request denied. Token doesn't exist. Returned a 400 Bad Request.\n";
					return $this-&gt;put(400, array("error" =&gt; "nonexistant"));
				}
				elseif(! unlink($ip . ".token")){
					echo "Request involintarily denied. Could not destroy token. Returned a 500 Internal Server Error.\n";
					return $this-&gt;put(500, array("error" =&gt; "cantdestroy"));
				}
				else{
					echo "Token removed. Returned a 200 OK.\n";
					return $this-&gt;put(200, array("successful" =&gt; true));
				}
			}
			elseif($path[2] == "chat"){
				echo "Wants to submit chat message. ";
				if(! @isset($path[3])){
					echo "Request denied. Token not supplied. Returned a 400 Bad Request.\n";
					return $this-&gt;put(400, array("error" =&gt; "notoken"));
				}
				elseif(! @isset($path[4])){
					echo "Request denied. User not supplied. Returned a 400 Bad Request.\n";
					return $this-&gt;put(400, array("error" =&gt; "nouser"));
				}
				elseif(! @isset($path[5])){
					echo "Request denied. Message not supplied. Returned a 400 Bad Request.\n";
					return $this-&gt;put(400, array("error" =&gt; "nomsg"));
				}
				elseif(! $this-&gt;checkToken($env['REMOTE_ADDR'], $path[4], $path[3])){
					echo "Request denied. Invalid token supplied. Returned a 400 Bad Request.\n";
					return $this-&gt;put(400, array("error" =&gt; "invalidtoken"));
				}
				$time=date("g:i A");
				$formatted=str_replace("%20", " ", $path[5]);
				if(! file_exists("chat.apples")){
					if(! file_put_contents("chat.apples", $path[4] . ": " . $path[5] . " ($time)\n")){
						echo "Request involuntarily denied. Could not write to chat.apples. Returning a 500 Internal Server Error.\n";
						return $this-&gt;put(500, array("error" =&gt; "cantwrite"));
					}
					echo "Written successfully.\n";
					return $this-&gt;put(200, array("chatted" =&gt; true));
				}
				else{
					if(! $data=file_get_contents("chat.apples")){
						echo "Request involintarily denied. Could not read chat.apples. Returning a 500 Internal Server Error.\n";
						return $this-&gt;put(500, array("error" =&gt; "cantread"));
					}
					elseif(! file_put_contents("chat.apples", $data . $path[4] . ": " . $path[5] . " ($time)\n")){
						echo "Request involuntairly. Could not write to chat.apples. Returning a 500 Internal Server Error.\n";
						return $this-&gt;put(500, array("error" =&gt; "cantwrite"));
					}
					echo "Written successfully.\n";
					return $this-&gt;put(200, array("chatted" =&gt; true));
				}
			}
			else{
			echo "Invalid call. Returned a 400 Bad Request.\n";
			$return=$this-&gt;put(400, array("error" =&gt; "invalid_call"));
			}
		}
		else{
			echo "Home page call. Returning a 200 OK.\n";
			return array(
				200,
				array("Content-Type" =&gt; "text/html"),
				array("&lt;html&gt;\n&lt;head&gt;\n&lt;title&gt;TDLive Apples API&lt;/title&gt;\n&lt;/head&gt;&lt;body&gt;&lt;h1&gt;Welcome to the Apples API!&lt;/h1&gt;&lt;br&gt;&lt;b&gt;If you are a server administrator, your Apples API installation is running correctly. Good job!&lt;br&gt;&lt;br&gt;If you ae not an admin, and would like to learn more about the Apples API, read on.&lt;/b&gt;&lt;br&gt;&lt;br&gt;The Apples API is a web service for a game called Apples written by &lt;a href='http://tdlive.org/'&gt;TDLive.org Inc&lt;/a&gt;. It uses RESTful API calls to interact with other players.&lt;br&gt;&lt;br&gt;You can learn about the Apples API on &lt;a href='http://apples.tdlive.org/'&gt;apples.tdlive.org&lt;/a&gt;&lt;/body&gt;&lt;/html&gt;")
			);
			
		}
		return $return;
	}
}

echo "

TDLive.org's
 _____                   _                     _____   _____   _
|  _  |                 | |                   |  _  | |  _  | | |
| | | |                 | |                   | | | | | | | | | |
| | | |                 | |                   | | | | | | | | | |
| |_| |                 | |                   | |_| | | |_| | | |
|  _  |  _____   _____  | |  ____   ____      |  _  | |  ___| | |
| | | | |  _  | |  _  | | | |  _ | | ___|     | | | | | |     | |
| | | | | |_| | | |_| | | | | ___| |___ |     | | | | | |     | |
|_| |_| |  ___| |  ___| |_| |____| |____|     |_| |_| |_|     |_|
        | |     | |                                        alpha
        | |     | |
        |_|     |_|

This application uses various open source materials:
Kelpie: http://getkelpie.com
PHP: http://php.net/

(c)2012 TDLive.org Inc. Some rights reserved;
Dual licensed under the Creative Commons BY-NC-SA license and
the GNU General Public License, v2 or later, available at:
http://creativecommons.org/licenses/by-nc-sa/3.0 and
http://gnu.org/copyleft/gpl, respectively. \n";
echo "Initializing server...\n";
$server = @new Kelpie_Server('0.0.0.0', 8000);
$server-&gt;start(new HelloWorldApp());
</pre></body></html>