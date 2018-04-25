<?php

use NFePHP\DA\Legacy\Common;
use NFePHP\Common\Certificate;

class Protocolo extends Common
{

    // chave da nfe
    public $chNFe;
    protected $prot;
    protected $c;

    function __construct($cfg = array())
    {
        $this->c = new Config();
        $this->local = $this->c->local;
        //echo $this->local;exit;
        $arr = [
            "atualizacao" => "2016-11-03 18:01:21",
            "tpAmb" => 1,
            "razaosocial" => "Escola de Engenharia de São Carlos",
            "cnpj" => "63025530002824",
            "siglaUF" => "SP",
            "schemes" => "PL008i2",
            "versao" => '3.10',
            "tokenIBPT" => "AAAAAAA",
            "CSC" => "GPB0JBWLUR6HWFTVEAS6RJ69GPCROFPBBB8G",
            "CSCid" => "000001",
            "proxyConf" => [
                "proxyIp" => "",
                "proxyPort" => "",
                "proxyUser" => "",
                "proxyPass" => ""
            ]
        ];
        //monta o config.json
        $configJson = json_encode($arr);
        //carrega o conteudo do certificado.
        $cert = file_get_contents($cfg['cert_file']);

        $this->tools = new NFePHP\NFe\Tools($configJson, Certificate::readPfx($cert, $cfg['cert_pwd']));
    }

    public function getChave()
    {
        if (empty($this->xml)) {
            return false;
        }
        if (empty($this->chave)) {
            $dom = new DomDocument();
            $dom->loadXml($this->xml);
            $this->chave = $dom->getElementsByTagName('chNFe')->item(0)->nodeValue;
        }
        return $this->chave;
    }

    /*
 * Consulta a chave de NFe na Sefaz
 * Caso esteja no disco não consulta novamente para evitar 'uso indevido'
 * todo: tem de dar uma validade no cache do disco ou possibilidade de dar refresh manual
 */
    public function consulta($chave)
    {
        $maxage = 360; // caso tenha mais de 10 mins, consulta de nvo a sefaz.

        if (!$this->chNFe = nfe_ws::validaChNFe($chave)) {
            return false;
        }

        $this->protArq = $this->local . $this->chNFe . '-prot.xml';
        $ret = [];
        $age = 0;
        if (is_file($this->protArq)) {
            $this->prot = file_get_contents($this->protArq);
            $age = time() - filemtime($this->protArq);
            $ret['age'] = Tools::msgTempo(time(), filemtime($this->protArq));
        }

        // se o protocolo for antigo ou se não exixtir
        if ($age > $maxage) {
            $this->prot = $this->tools->sefazConsultaChave($chave);
            file_put_contents($this->protArq, $this->prot);
            $ret['age'] = 0;
        }
        $ret = array_merge($ret, $prot_arr = $this->parse());

        return $ret;
    }

    /*
     * Retorna em array os dados relevantes de um retorno de consulta de NFe
    */
    public function parse()
    {
        $ret = [];
        $cons = new \DOMDocument('1.0', 'UTF-8');
        $cons->preserveWhiteSpace = false;
        $cons->formatOutput = false;
        $cons->loadXML($this->prot);

        // verifica se houve retorno válido
        if (!$infProt = $cons->getElementsByTagName('infProt')->item(0)) {
            $ret['status'] = 'retorno sem infProt';
            return $ret;
        }

        // esta primeira data é a da consulta do protocolo
        $ret['dhConsulta'] = date("d/m/Y - H:i:s",
            $this->pConvertTime($cons->getElementsByTagName('dhRecbto')->item(0)->nodeValue));

        // vamos pegar a situação atual
        $ret['cStat'] = $cons->getElementsByTagName('cStat')->item(0)->nodeValue;
        $ret['xMotivo'] = $cons->getElementsByTagName('xMotivo')->item(0)->nodeValue;
        $ret['tpAmb'] = $cons->getElementsByTagName('tpAmb')->item(0)->nodeValue;


        // vamos gerar o array de eventos, começando pelo protocolo de autorização
        $protNFe = $cons->getElementsByTagName('protNFe')->item(0);
        $ret['eventos'][0]['tpEvento'] = $protNFe->getElementsByTagName('cStat')->item(0)->nodeValue;
        $ret['eventos'][0]['descEvento'] = $protNFe->getElementsByTagName('xMotivo')->item(0)->nodeValue;
        $ret['eventos'][0]['nProt'] = $protNFe->getElementsByTagName('nProt')->item(0)->nodeValue;
        $ret['eventos'][0]['dhEvento'] = date("d/m/Y - H:i:s",
            $this->pConvertTime($protNFe->getElementsByTagName('dhRecbto')->item(0)->nodeValue));

        $ret['eventos'][0]['digVal'] = $protNFe->getElementsByTagName('digVal')->item(0)->nodeValue;

        // agora os demais eventos se houver
        $eventos = $cons->getElementsByTagName('procEventoNFe');
        foreach ($eventos as $evento) {
            $i = $evento->getElementsByTagName('nSeqEvento')->item(0)->nodeValue;
            $ret['eventos'][$i]['tpEvento'] = $evento->getElementsByTagName('tpEvento')->item(0)->nodeValue;
            $ret['eventos'][$i]['descEvento'] = $evento->getElementsByTagName('descEvento')->item(0)->nodeValue;

            // pega a data do infEvento e não do retorno
            $ret['eventos'][$i]['dhEvento'] = date("d/m/Y - H:i:s",
                $this->pConvertTime($evento->getElementsByTagName('dhEvento')->item(0)->nodeValue));

            // aqui pega o nprot do retEvento e não do infEvento
            $retEvento = $evento->getElementsByTagName('retEvento')->item(0);
            $ret['eventos'][$i]['nProt'] = $retEvento->getElementsByTagName('nProt')->item(0)->nodeValue;
        }
        return $ret;
    }
}