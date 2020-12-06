<?php
namespace Discord;

use React;
use Psr\Log\NullLogger;
use Evenement\EventEmitterTrait;
use Evenement\EventEmitterInterface;

class Gateway implements EventEmitterInterface {
	use EventEmitterTrait;
	
	const OPCODE_DISPATCH					= 0;
	const OPCODE_HEARTBEAT					= 1;
	const OPCODE_IDENTIFY					= 2;
	const OPCODE_STATUS_UPDATE				= 3;
	const OPCODE_VOICE_STATE_UPDATE			= 4;
	const OPCODE_VOICE_SERVER_PING			= 5;
	const OPCODE_RESUME						= 6;
	const OPCODE_RECONNECT					= 7;
	const OPCODE_REQUEST_GUILDS_MEMBERS		= 8;
	const OPCODE_INVALID_SESSION			= 9;
	const OPCODE_HELLO						= 10;
	const OPCODE_HEARTBEAT_ACK				= 11;
	
	const GATEWAY_UNKNOWN_ERROR				= 4000;
	const GATEWAY_UNKNOWN_OPCODE			= 4001;
	const GATEWAY_DECODE_ERROR				= 4002;
	const GATEWAY_NOT_AUTHENTICATED			= 4003;
	const GATEWAY_AUTHENTICATION_ERROR		= 4004;
	const GATEWAY_ALREADY_AUTHENTICATED		= 4005;
	const GATEWAY_INVALID_SEQ				= 4007;
	const GATEWAY_RATE_LIMITED				= 4008;
	const GATEWAY_SESSION_TIMED_OUT			= 4009;
	const GATEWAY_INVALID_SHARD				= 4010;
	const GATEWAY_SHARDING_REQUIRED			= 4011;
	const GATEWAY_INVALID_API_VERSION		= 4012;
	const GATEWAY_INVALID_INTENTS			= 4013;
	const GATEWAY_DISALLOWED_INTENTS		= 4014;
	
	protected $options;
	protected $loop;
	protected $logger;
	protected $ws, $ws_connector;
	protected $heartbeat;
	protected $seq;
	protected $connected = false;
	protected $last_disconnect = false;
	protected $closed_by_user = false;
	
	public function __construct($loop, $options = []) {
		$this->loop = $loop;
		
		$this->options = array_merge([
			'url'			=> 'wss://gateway.discord.gg/',
			'token'			=> '',
			'logger'		=> NULL
		], $options);
		
		$this->ws_connector = new \Ratchet\Client\Connector($this->loop);
		
		$this->logger = $this->options['logger'] ?: new NullLogger();
	}
	
	public function connect() {
		$deferred = new React\Promise\Deferred();
		
		if ($this->connected) {
			$deferred->resolve();
			return $deferred->promise();
		}
		
		$this->closed_by_user = false;
		$this->connected = true;
		
		$this->logger->debug("connecting to: ".$this->options['url']);
		
		$connector = $this->ws_connector;
		$connector($this->options['url'])->then(function ($ws) use ($deferred) {
			$this->ws = $ws;
			$this->seq = NULL;
			
			$this->logger->debug("connected to: ".$this->options['url']);
			
			$deferred->resolve();
			$this->emit('connected');
			
			$ws->on('close', function ($code, $reason) {
				return $this->wsClose($code, $reason);
			});
			
			$ws->on('message', function ($message) {
				return $this->wsMessage($message);
			});
			
			$ws->on('error', function ($error) {
				return $this->wsError($error);
			});
		}, function ($e) use ($deferred) {
			$deferred->reject();
			
			$this->logger->debug("websocket connect error: ".$e->getMessage());
			$this->reconnect();
		});
		
		return $deferred->promise();
	}
	
	public function reconnect() {
		$this->disconnect();
		
		if (microtime(true) - $this->last_disconnect < 60) {
			$wait = rand(3, 10);
			$this->logger->warning("too fast reconenct, wait $wait seconds...");
			
			$deferred = new React\Promise\Deferred();
			$this->loop->addTimer($wait, function () use ($deferred) {
				$this->connect()
					->then(React\Partial\bind([$deferred, 'resolve']))
					->otherwise(React\Partial\bind([$deferred, 'reject']));
			});
			return $deferred->promise();
		} else {
			return $this->connect();
		}
	}
	
	public function disconnect() {
		$this->last_disconnect = microtime(true);
		
		if ($this->ws) {
			$this->closed_by_user = true;
			$this->ws->close();
		}
		
		if ($this->connected)
			$this->emit('disconnected');
		
		$this->ws = false;
		$this->connected = false;
		$this->heartbeat(false);
	}
	
	protected function heartbeat($timeout) {
		if ($this->heartbeat) {
			$this->loop->cancelTimer($this->heartbeat);
			$this->heartbeat = false;
		}
		
		if ($timeout) {
			$this->logger->debug("set heartbeat timer: $timeout ms");
			$this->heartbeat = $this->loop->addPeriodicTimer($timeout / 1000, function () {
				$this->sendHeartbeat();
			});
		} else {
			$this->logger->debug("disable heartbeat timer");
		}
	}
	
	protected function sendHeartbeat() {
		if ($this->ws) {
			$this->logger->debug("send heartbeat");
			$this->heartbeat_start = microtime(true);
			$this->ws->send(json_encode([
				'op'	=> self::OPCODE_HEARTBEAT,
				'd'		=> $this->seq
			]));
		}
	}
	
	protected function wsMessage($ws_message) {
		if (!$this->connected)
			return;
		
		$message = json_decode($ws_message->getPayload());
		
		if (!is_null($message->s))
			$this->seq = $message->s;
		
		switch ($message->op) {
			case self::OPCODE_HELLO:
				$this->heartbeat($message->d->heartbeat_interval);
				
				$this->ws->send(json_encode([
					'op'		=> self::OPCODE_IDENTIFY,
					'd'			=> [
						'token'				=> $this->options['token'],
						'large_threshold'	=> 250,
						'compress'			=> false,
						'properties'		=> [
							'$os'			=> PHP_OS,
							'$browser'		=> 'php',
							'$device'		=> 'php'
						]
					]
				]));
			break;
			
			case self::OPCODE_HEARTBEAT:
				$this->logger->debug("force heartbeat");
				$this->sendHeartbeat();
			break;
			
			case self::OPCODE_RECONNECT:
				$this->logger->debug("force reconnect");
				$this->ws->close();
			break;
			
			case self::OPCODE_INVALID_SESSION:
				$this->logger->error("invalid session, force reconnect");
				$this->ws->close();
			break;
			
			case self::OPCODE_HEARTBEAT_ACK:
				$ping_time = round((microtime(true) - $this->heartbeat_start) * 1000, 2);
				$this->logger->debug("heartbeat ack, $ping_time ms");
			break;
			
			case self::OPCODE_DISPATCH:
				$this->logger->debug("dispatch: ".$message->t);
				
				if ($message->t == "READY") {
					$this->emit('ready');
					$this->logger->debug("new session ready: ".$message->d->session_id);
				}
				
				$this->emit('message', [$message->t, $message->d]);
			break;
			
			default:
				$this->logger->warning("unknown opcode: ".$message->op);
			break;
		}
	}
	
	protected function wsError($error) {
		if (!$this->connected)
			return;
		
		$this->logger->error("websocket error: $error");
	}
	
	protected function wsClose($code, $reason) {
		if (!$this->connected)
			return;
		
		$this->logger->error("websocket close, code=$code, reason=$reason");
		$this->ws = false;
		
		if ($code == self::GATEWAY_AUTHENTICATION_ERROR) {
			$this->disconnect();
			$this->logger->debug("fatal error occured, no reconnect");
		} else {
			if ($this->closed_by_user) {
				$this->logger->debug("closed by user, no reconnect");
			} else {
				$this->reconnect();
			}
		}
	}
}
