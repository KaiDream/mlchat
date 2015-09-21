#!/usr/bin/php
<?php
declare(ticks = 1);
class MLChat {

    const MSG_PRIVATE = "#";
    const MSG_PUBLIC = ":";

    protected $settings;
    /**
     * Connection Information for Slack/IRC
     */
    protected $ircServer;
    protected $ircPort;
    protected $ircChannel;
    protected $ircPassword;
    protected $ircNick;
    protected $ircFifoFile;
    protected $ircFifo;
    protected $ircSocket;

    /**
     * Connection Information for BBS
     */
    protected $bbsServer;
    protected $bbsPort;
    protected $bbsPassword;
    protected $bbsUser;
    protected $bbsFifoFile;
    protected $bbsFifo;
    protected $bbsSocket;

    public function __construct()
    {
        $this->settings = parse_ini_file("mlchat.ini",true);
        $this->ircServer = $this->settings["irc"]["server"];
        $this->ircPort = $this->settings["irc"]["port"];
        $this->ircChannel = $this->settings["irc"]["channel"];
        $this->ircPassword = $this->settings["irc"]["password"];
        $this->ircNick = $this->settings["irc"]["nick"];
        $this->ircFifoFile = $this->settings["irc"]["fifoFile"];
        $this->bbsServer = $this->settings["bbs"]["server"];
        $this->bbsPort = $this->settings["bbs"]["port"];
        $this->bbsPassword = $this->settings["bbs"]["password"];
        $this->bbsUser = $this->settings["bbs"]["user"];
        $this->bbsFifoFile = $this->settings["bbs"]["fifoFile"];
    }
    /**
     * Fork IRC/BBS Process
     */
    public function run() {
        $this->sigInit();
        set_time_limit(0);
        $this->initFifoHandlers();
        $ircStart = pcntl_fork();
        $bbsStart = pcntl_fork();

        if($bbsStart == 0 && $ircStart != 0) {
            $mlbot = new MLChat();
            $mlbot->bbsProcess();
            exit (0);
        }else if($ircStart == 0 && $bbsStart != 0) {
            $mlbot = new MLChat();
            $mlbot->ircProcess();
            exit (0);
        }
        if($ircStart != 0 && $bbsStart != 0) {
            while(1) { sleep(5); }
        }
    }

    public function ircProcess() {
        $this->sigInit();
        if ($this->ircInit()) {
            $this->ircConnect();
            $ircClient = pcntl_fork();
            $bbsReader = pcntl_fork();

            if($ircClient == 0 && $bbsReader != 0) {
                $this->ircSocketReadIrcFifoWrite();
                exit (0);
            } else if($bbsReader == 0 && $ircClient != 0) {
                $this->bbsSocketWriteIrcFifoRead();
                exit (0);
            }
            if($ircClient != 0 && $bbsReader != 0) {
                while(1) { sleep(5); }
            }
        }
    }

    public function bbsProcess() {
        $this->sigInit();
        if ($this->bbsInit()) {
            $bbsClient = pcntl_fork();
            $ircReader = pcntl_fork();

            if($bbsClient == 0 && $ircReader != 0) {
                $this->bbsSocketReadBbsFifoWrite();
                exit (0);
            } else if($ircReader == 0 && $bbsClient != 0) {
                $this->ircSocketWriteBBSFifoRead();
                exit (0);
            }
            if($bbsClient != 0 && $ircReader != 0) {
                while(1) { sleep(5); }
            }
        }
    }

    protected function initFifoHandlers() {
        @unlink($this->ircFifoFile);
        @unlink($this->bbsFifoFile);
        posix_mkfifo($this->ircFifoFile, 0600);
        posix_mkfifo($this->bbsFifoFile, 0600);
    }

    protected function bbsSocketReadBbsFifoWrite() {
        $inLobby = false;
        $priv = false;
        $privBuffer = "";
        while (1) {
            while ($data = fgets($this->bbsSocket, 8192)) {
                flush();
                $this->bbsConect($data);
                if(!$inLobby && strstr($data, "Welcome to the Lobby.")) {
                    $inLobby = true;
                }
                if($inLobby) {
                    if(!$priv && stristr(substr(trim($data), 0,4), "/who")) {
                        $priv = true;
                        $privBuffer = "#".str_replace("/who ", "",trim($data))."#";
                        $data = "";
                    }

                    if(!$priv && $this->hasText($data) && $data[0] != "/") {
                        $this->bbsCache($data, self::MSG_PUBLIC);
                    } else if($priv) {
                        $privBuffer .= $data;
                    }

                    if($priv && strstr($data, "total users online.")) {
                        $priv = false;
                        $this->bbsCache($privBuffer, self::MSG_PRIVATE);
                        $privBuffer = "";
                    }
                    //if($this->hasText($data)) {
                        print("[BBS Server] $data\n");
                    //}
                }
            }
        }
    }

    protected function bbsInit() {
        $result = false;
        set_time_limit(0);
        $this->bbsSocket = fsockopen($this->bbsServer, $this->bbsPort, $eN, $eS);
        stream_set_blocking($this->bbsSocket,0);
        if($this->bbsSocket) {
            stream_set_timeout($this->bbsSocket, 60 * 15);
            $this->ircFifo = fopen($this->ircFifoFile, 'c+');
            $this->bbsFifo = fopen($this->bbsFifoFile, 'c+');
            stream_set_blocking($this->bbsFifo,0);
            $result = true;
        } else {
            print("[BBS Server Error] $eS : $eN");
        }
        return $result;
    }

    protected function bbsConect($data) {
        if(strstr(trim($data), "attempt")) {
            fwrite($this->bbsSocket, "/$this->bbsUser\r");
            fwrite($this->bbsSocket, "$this->bbsPassword\r");
            fwrite($this->bbsSocket, "\rC\r");
        }
    }

    /**
     * Write IRC Data to File Cache
     * @param $data
     * @param string $prefix
     */
    protected function bbsCache($data, $prefix = "") {
        fwrite($this->ircFifo, "\n");
        fwrite($this->ircFifo, $prefix.base64_encode($data));
    }

    protected function ircSocketReadIrcFifoWrite() {
        while (1) {
            while ($data = fgets($this->ircSocket, 8192)) {
                flush();
                $exData = explode(' ', $data);
                if($exData[0] == "PING") {
                    fwrite($this->ircSocket, "PONG " . $exData[1] . "\n");
                    $this->ircCache("/");
                    print("[Irc Server PING!]\n");
                } else if($exData[1] == "PRIVMSG" && $exData[2] == $this->ircChannel) {
                    $userdetails = explode("!", $data);
                    $message = explode("@irc.tinyspeck.com PRIVMSG {$this->ircChannel} :", $data);
                    $this->ircCache("{$userdetails[0]}: {$message[1]}");
                } else if($exData[1] == "PRIVMSG" && $exData[2] == "chatroom") {
                    switch(trim($exData[3])) {
                        case ":VERSION":
                            break;
                        default:
                            $this->ircPrivateMessaging($exData, $data);
                            break;
                    }
                } else {
                    print("[Irc Server] $data");
                }
                print("[Irc Server] $data");
            }
        }
    }

    protected function ircPrivateMessaging($exData, $data) {
        $user = explode("!", ltrim($exData[0], ":"));
        print("[Irc Server PMSG] {$exData[0]}{$exData[3]} - $data");
        if(stristr(substr($exData[3], 0,6), ":!HELP")) {
            fwrite($this->ircSocket, "PRIVMSG $user[0] :The following commands are valid:\n");
            fwrite($this->ircSocket, "PRIVMSG $user[0] :>!HELP (command) - this help menu\n");
            fwrite($this->ircSocket, "PRIVMSG $user[0] :>!WHO - Show who is online\n");
        } else if (stristr(substr($exData[3], 0,5), ":!WHO")) {
            $this->ircCache("/who $user[0]");
        } else {
            fwrite($this->ircSocket, "PRIVMSG $user[0] ::red_circle:Invalid command, for help type !HELP\n");
        }
    }

    /**
     * Read from Irc->BBSFifo and write to BBSStream
     */
    protected function ircSocketWriteBBSFifoRead() {
        while (1) {
            while ($data = fgets($this->bbsFifo,8192)) {
                flush();
                $data = base64_decode($data);
                if(!empty($data)) {
                    if(trim($data) == "/") {
                        print("[BBS Server PING!]\n");
                        fwrite($this->bbsSocket, "\r.\r");
                    } else if(stristr(substr(trim($data), 0,4), "/who")) {
                        print("[IRC->BBS COMMAND:WHO]\n");
                        fwrite($this->bbsSocket, "\r{$data}\r");
                    } else if($this->hasText($data)) {
                        print("[IRC->BBS] {$data}");
                        fwrite($this->bbsSocket, "\r{$data}\r");
                    } else {

                    }
                }
            }
        }
    }

    /**
     * Read from BBS->IrcFifo and write to IRCStream
     */
    protected function bbsSocketWriteIrcFifoRead() {
        while (1) {
            while ($data = fgets($this->ircFifo, 8192)) {
                flush();
                if($data[0] == "#") {
                    $buffer = base64_decode(substr($data,1));
                    $params = explode("#", $buffer);
                    $messages = explode("\r\n", $params[2]);
                    foreach($messages as $message) {
                        if(trim($message) != "") {
                            print("[BBS->IRC COMMAND:WHO] {$message}\n");
                            fwrite($this->ircSocket, "PRIVMSG $params[1] :```$message```\n");
                        }
                    }
                } else if($data[0] == ":") {
                    $buffer = base64_decode(substr($data,1));
                    print("[BBS->IRC] {$buffer}");
                    fwrite($this->ircSocket, "PRIVMSG {$this->ircChannel} :{$buffer}\n");
                } else {
                    $buffer = base64_decode($data);
                    if($this->hasText($buffer)) {
                        print("[BBS->IRC] {$buffer}");
                        fwrite($this->ircSocket, "PRIVMSG {$this->ircChannel} :{$buffer}\n");
                    }
                }
            }
        }
    }

    protected function ircInit() {
        $result = false;
        set_time_limit(0);
        $this->ircSocket = fsockopen($this->ircServer, $this->ircPort, $eN, $eS);
        stream_set_blocking($this->ircSocket,0);
        if($this->ircSocket) {
            $this->ircFifo = fopen($this->ircFifoFile, 'c+');
            $this->bbsFifo = fopen($this->bbsFifoFile, 'c+');
            stream_set_blocking($this->ircFifo,0);
            $result = true;
        } else {
            print("[Irc Server Error] $eS : $eN");
        }
        return $result;
    }

    /**
     * Connection to Slack IRC Server
     */
    protected function ircConnect() {
        fwrite($this->ircSocket, "PASS $this->ircPassword\n");
        fwrite($this->ircSocket, "USER $this->ircNick\n");
        fwrite($this->ircSocket, "NICK $this->ircNick\n");
        fwrite($this->ircSocket, "JOIN $this->ircChannel\n");
    }

    /**
     * Write IRC Data to File Cache
     * @param $data
     */
    protected function ircCache($data, $prefix = "") {
        fwrite($this->bbsFifo, "\n");
        fwrite($this->bbsFifo, $prefix.base64_encode($data));
    }

    protected function sigInit() {
        pcntl_signal(SIGUSR1, "MLChat::sigHandle");
	pcntl_signal(SIGTERM, "MLChat::sigHandle");
        pcntl_signal(SIGINT, "MLChat::sigHandle");
    }

    protected function hasText($str) {
        return preg_match('/[A-Za-z].*[0-9]|[0-9].*[A-Za-z]/', $str);
    }
    public static function sigHandle($sig) {
        switch($sig) {
            case SIGTERM:
            case SIGKILL:
            case SIGINT:
	    case SIGUSR1:
                exit;
        }
        return true;
    }
}

$mlbot = new MLChat();
$mlbot->run();
