<?php
declare(strict_types=1);
/** Strict read-only Modbus/TCP client: function codes 01, 02, 03, 04 ONLY. */
final class ModbusClient {
    private $socket;
    private int $transaction = 0;
    public function __construct(string $host, int $port, float $timeout = 2.5) {
        if (!filter_var($host, FILTER_VALIDATE_IP) && !preg_match('/^[a-zA-Z0-9][a-zA-Z0-9.-]{0,252}$/D', $host)) throw new InvalidArgumentException('Host inválido');
        if ($port < 1 || $port > 65535) throw new InvalidArgumentException('Porta inválida');
        $errno=0; $errstr='';
        $this->socket = @stream_socket_client('tcp://' . $host . ':' . $port, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT);
        if (!$this->socket) throw new RuntimeException('Falha TCP: ' . $errstr);
        stream_set_timeout($this->socket, (int)$timeout, (int)(($timeout-floor($timeout))*1000000));
    }
    public function __destruct() { if (is_resource($this->socket)) fclose($this->socket); }
    private function exact(int $n): string {
        $r = ''; while (strlen($r)<$n && !feof($this->socket)) { $s=fread($this->socket,$n-strlen($r)); if ($s === false || $s === '') break; $r .= $s; }
        if (strlen($r)!==$n) throw new RuntimeException('Timeout / resposta Modbus truncada');
        return $r;
    }
    public function read(int $unit, int $fc, int $address, int $count): array {
        if (!in_array($fc,[1,2,3,4],true)) throw new InvalidArgumentException('Somente funções de leitura');
        if ($unit<0 || $unit>247 || $address<0 || $address>65535 || $count<1 || $count>($fc<=2?2000:125) || ($address+$count)>65536) throw new InvalidArgumentException('Endereço/quantidade inválidos');
        $tx = (++$this->transaction) & 0xffff;
        $pdu=pack('Cnn',$fc,$address,$count);
        $mbap=pack('nnnC',$tx,0,strlen($pdu)+1,$unit);
        $written = fwrite($this->socket, $mbap.$pdu);
        if ($written !== strlen($mbap.$pdu)) throw new RuntimeException('Envio incompleto');
        $hdr=unpack('ntx/nprotocol/nlength/Cunit',$this->exact(7));
        if ($hdr['tx']!==$tx || $hdr['protocol']!==0 || $hdr['unit']!==$unit || $hdr['length']<3 || $hdr['length']>254) throw new RuntimeException('MBAP inválido');
        $resp=$this->exact($hdr['length']-1);
        $function=ord($resp[0]);
        if ($function===($fc|0x80)) throw new RuntimeException('Exceção Modbus: '.ord($resp[1]??"\0"));
        if ($function!==$fc || strlen($resp)<2) throw new RuntimeException('Função da resposta divergente');
        $bytes=ord($resp[1]); $payload=substr($resp,2);
        $expected=$fc<=2 ? (int)ceil($count/8) : $count*2;
        if ($bytes!==$expected || strlen($payload)!==$expected) throw new RuntimeException('Quantidade de bytes incorreta');
        if ($fc<=2) { $out=[]; for($i=0;$i<$count;$i++) $out[]=(ord($payload[intdiv($i,8)]) >> ($i%8)) & 1; return $out; }
        return array_values(unpack('n*',$payload));
    }
    public static function decode(array $words, string $type, int $scale): float {
        $first=(int)($words[0]??0);
        if ($type==='bool') return $first ? 1.0 : 0.0;
        if ($type==='bool_not') return $first ? 0.0 : 1.0;
        if ($type==='s16') $v=$first>=32768?$first-65536:$first;
        elseif ($type==='u16') $v=$first;
        elseif ($type==='u32' || $type==='s32') {
            if (count($words)<2) throw new RuntimeException('32 bits exige 2 words');
            // DEIF AB/CD: high word in lower register address.
            $v=($first<<16)|(int)$words[1];
            if ($type==='s32' && $v >= 2147483648) $v-=4294967296;
        } else throw new InvalidArgumentException('Tipo não reconhecido');
        return (float)$v * pow(10,-$scale);
    }
}
