<?php
/**
 * =============================================
 * URS Pharmacy ERP - ZATCA Phase 2 Integration
 * النسخة المصلحة الكاملة
 * =============================================
 * الإصلاحات:
 * 1. توليد CSR صحيح باستخدام Python (secp256k1 + ZATCA extensions)
 * 2. TSTZATCA-Code-Signing للـ sandbox/simulation
 * 3. PREZATCA-Code-Signing للـ production
 * 4. كل الـ endpoints صحيحة
 */

// ==================== Phase 1 Functions ====================

function generateInvoiceUUID() {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0,0xffff),mt_rand(0,0xffff),mt_rand(0,0xffff),
        mt_rand(0,0x0fff)|0x4000,mt_rand(0,0x3fff)|0x8000,
        mt_rand(0,0xffff),mt_rand(0,0xffff),mt_rand(0,0xffff));
}

function generateInvoiceHash($d) {
    return base64_encode(hash('sha256',implode('|',[
        $d['invoice_number']??'',$d['invoice_date']??'',
        $d['grand_total']??'0',$d['vat_amount']??'0',
        $d['tax_number']??'']),true));
}

function generateZATCAQR($d,$co) {
    $s=$co['company_name']??'';$t=$co['tax_number']??'';
    $ts=($d['invoice_date']??date('Y-m-d')).'T'.date('H:i:s');
    $tot=number_format(floatval($d['grand_total']??0),2,'.','');
    $vat=number_format(floatval($d['vat_amount']??0),2,'.','');
    $tlv='';
    $enc=function($tag,$val){$len=strlen($val);return chr($tag).chr($len).$val;};
    $tlv.=$enc(1,$s).$enc(2,$t).$enc(3,$ts).$enc(4,$tot).$enc(5,$vat);
    return base64_encode($tlv);
}

function getInvoiceMetadata($inv,$co) {
    return ['uuid'=>generateInvoiceUUID(),
        'hash'=>generateInvoiceHash(array_merge($inv,['tax_number'=>$co['tax_number']??''])),
        'qr'=>generateZATCAQR($inv,$co)];
}

function productRequiresPrescription($pdo,$pid) {
    $s=$pdo->prepare("SELECT requires_prescription,is_narcotic FROM products WHERE id=? AND tenant_id=?");
    $s->execute([$pid,getTenantId()]);$p=$s->fetch();
    return $p&&($p['requires_prescription']||$p['is_narcotic']);
}

function gregorianToHijri($date) {
    $ts=strtotime($date);$d=date('d',$ts);$m=date('m',$ts);$y=date('Y',$ts);
    $jd=gregoriantojd($m,$d,$y);$l=$jd-1948440+10632;$n=intval(($l-1)/10631);
    $l=$l-10631*$n+354;
    $j=(intval((10985-$l)/5316))*(intval((50*$l)/17719))+(intval($l/5670))*(intval((43*$l)/15238));
    $l=$l-(intval((30-$j)/15))*(intval((17719*$j)/50))-(intval($j/16))*(intval((15238*$j)/43))+29;
    $m=intval((24*$l)/709);$d=$l-intval((709*$m)/24);$y=30*$n+$j-30;
    return sprintf('%04d-%02d-%02d',$y,$m,$d);
}

// ==================== Phase 2: ZATCA Integration Service ====================

class ZATCAIntegrationService {

    private $pdo;
    private $tenantId;
    private $branchId;

    // ★ مسار ZATCA SDK الرسمي
    const SDK_JAR  = '/opt/zatca-sdk/Apps/cli-3.0.8-jar-with-dependencies.jar';
    const SDK_DIR  = '/opt/zatca-sdk';
    const JAVA_BIN = '/usr/bin/java';

    const SANDBOX_URL    = 'https://gw-fatoora.zatca.gov.sa/e-invoicing/developer-portal';
    const SIMULATION_URL = 'https://gw-fatoora.zatca.gov.sa/e-invoicing/simulation';
    const PRODUCTION_URL = 'https://gw-fatoora.zatca.gov.sa/e-invoicing/core';

    const NS_INVOICE = 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2';
    const NS_CAC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';
    const NS_CBC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';
    const NS_EXT = 'urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2';
    const NS_SIG = 'urn:oasis:names:specification:ubl:schema:xsd:CommonSignatureComponents-2';
    const NS_SAC = 'urn:oasis:names:specification:ubl:schema:xsd:SignatureAggregateComponents-2';
    const NS_SBC = 'urn:oasis:names:specification:ubl:schema:xsd:SignatureBasicComponents-2';
    const NS_DS  = 'http://www.w3.org/2000/09/xmldsig#';
    const NS_XADES = 'http://uri.etsi.org/01903/v1.3.2#';

    public function __construct($pdo, $tenantId = null, $branchId = null) {
        $this->pdo = $pdo;
        $this->tenantId = $tenantId ?? (isset($_SESSION['tenant_id']) ? intval($_SESSION['tenant_id']) : null);
        $this->branchId = $branchId ?? (isset($_SESSION['branch_id']) ? intval($_SESSION['branch_id']) : null);
    }

    // ===== فحص التفعيل =====

    public function isEnabled(): bool {
        if (!$this->tenantId) return false;
        try {
            $s = $this->pdo->prepare("SELECT t.zatca_enabled_by_admin, c.zatca_enabled, c.zatca_onboarding_status FROM tenants t LEFT JOIN compliance_settings c ON c.tenant_id=t.id WHERE t.id=?");
            $s->execute([$this->tenantId]); $r = $s->fetch();
            return $r && $r['zatca_enabled_by_admin']==1 && $r['zatca_enabled']==1 && $r['zatca_onboarding_status']==='active';
        } catch (\Exception $e) { return false; }
    }

    public function isEnabledByAdmin(): bool {
        if (!$this->tenantId) return false;
        try {
            $s = $this->pdo->prepare("SELECT zatca_enabled_by_admin FROM tenants WHERE id=?");
            $s->execute([$this->tenantId]); $r = $s->fetch();
            return $r && $r['zatca_enabled_by_admin']==1;
        } catch (\Exception $e) { return false; }
    }

    public function getSettings(): array {
        try {
            $s = $this->pdo->prepare("SELECT * FROM compliance_settings WHERE tenant_id=?");
            $s->execute([$this->tenantId]);
            return $s->fetch() ?: [];
        } catch (\Exception $e) { return []; }
    }

    public function saveSettings(array $data): bool {
        try {
            $existing = $this->getSettings();
            if ($existing) {
                $f=[]; $v=[];
                foreach ($data as $k=>$val) { $f[]="$k=?"; $v[]=$val; }
                $v[] = $this->tenantId;
                $this->pdo->prepare("UPDATE compliance_settings SET ".implode(',',$f).",updated_at=NOW() WHERE tenant_id=?")->execute($v);
            } else {
                $data['tenant_id'] = $this->tenantId;
                $cols = implode(',', array_keys($data));
                $ph = implode(',', array_fill(0, count($data), '?'));
                $this->pdo->prepare("INSERT INTO compliance_settings ($cols) VALUES ($ph)")->execute(array_values($data));
            }
            return true;
        } catch (\Exception $e) { return false; }
    }

    public function getBaseUrl(): string {
        $env = ($this->getSettings())['zatca_env'] ?? 'sandbox';
        if ($env==='production') return self::PRODUCTION_URL;
        if ($env==='simulation') return self::SIMULATION_URL;
        return self::SANDBOX_URL;
    }

    // ===== 1. توليد CSR باستخدام ZATCA SDK الرسمي =====

    public function generateCSR(array $p): array {
        $cn    = $p['common_name']    ?? 'EGS-URS-Pharmacy';
        $sn    = $p['serial_number']  ?? '1-URS|2-1.0|3-'.uniqid();
        $orgId = $p['org_identifier'] ?? '';
        $ou    = $p["org_unit_name"]  ?? "";
        // SDK يحتاج 10 أرقام من الـ TIN
        if (strlen($ou) !== 10 || !ctype_digit($ou)) {
            $ou = substr(preg_replace("/[^0-9]/", "", $orgId), 0, 10);
        }
        $org   = $p['org_name']       ?? '';
        // SDK يحتاج org_name 10 أرقام كمان
        if (strlen($org) !== 10 || !ctype_digit($org)) {
            $org = substr(preg_replace('/[^0-9]/', '', $orgId), 0, 10);
        }
        $it    = $p['invoice_type']   ?? '0100';
        $loc   = $p['location']       ?? '';
        $ind   = $p['industry']       ?? 'Pharmacy';

        if (!preg_match('/^3\d{13}3$/', $orgId))
            return ['success'=>false, 'error'=>'رقم الضريبة غير صحيح - لازم يكون 15 رقم يبدأ وينتهي بـ 3'];

        // مجلد مؤقت للـ CSR
        $dir     = sys_get_temp_dir() . '/zatca_' . $this->tenantId . '_' . time();
        @mkdir($dir, 0700, true);
        $cfgFile = "$dir/csr.properties";
        $keyFile = "$dir/key.pem";
        $csrFile = "$dir/csr.pem";

        // كتابة الـ config file
        $cfg = "csr.common.name={$cn}\n";
        $cfg .= "csr.serial.number={$sn}\n";
        $cfg .= "csr.organization.identifier={$orgId}\n";
        $cfg .= "csr.organization.unit.name={$ou}\n";
        $cfg .= "csr.organization.name={$org}\n";
        $cfg .= "csr.country.name=SA\n";
        $cfg .= "csr.invoice.type={$it}\n";
        $cfg .= "csr.location.address={$loc}\n";
        $cfg .= "csr.industry.business.category={$ind}\n";
        file_put_contents($cfgFile, $cfg);

        // تشغيل الـ SDK
        $cmd = self::JAVA_BIN . ' -Djdk.sunec.disableNative=false'
             . ' -jar ' . escapeshellarg(self::SDK_JAR)
             . ' -csr'
             . ' -csrConfig ' . escapeshellarg($cfgFile)
             . ' -privateKey ' . escapeshellarg($keyFile)
             . ' -generatedCsr ' . escapeshellarg($csrFile)
             . ' 2>&1';

        $output = shell_exec($cmd);

        if (!file_exists($keyFile) || !file_exists($csrFile)) {
            @array_map('unlink', [$cfgFile, $keyFile, $csrFile]);
            @rmdir($dir);
            return ['success'=>false, 'error'=>'فشل توليد CSR بالـ SDK: ' . ($output ?? 'Unknown error')];
        }

        $pk     = file_get_contents($keyFile);
        $csrPem = file_get_contents($csrFile);
        $csrB64 = trim(str_replace(["\r","\n","-----BEGIN CERTIFICATE REQUEST-----","-----END CERTIFICATE REQUEST-----"], '', $csrPem));

        @array_map('unlink', [$cfgFile, $keyFile, $csrFile]);
        @rmdir($dir);

        if (!$pk || !$csrB64) {
            return ['success'=>false, 'error'=>'CSR أو المفتاح فارغ'];
        }

        $this->saveSettings([
            'zatca_private_key'       => $pk,
            'zatca_csr'               => $csrB64,
            'zatca_egs_serial'        => $sn,
            'zatca_functionality_map' => $it,
            'zatca_onboarding_status' => 'csr_generated',
            'zatca_last_error'        => null,
        ]);

        return ['success'=>true, 'csr'=>$csrB64, 'private_key'=>$pk];
    }

    /**
     * تشغيل Python script وإرسال الـ input عبر stdin
     */
    private function runPython(string $bin, string $script, string $jsonInput): array {
        // طريقة 1: proc_open (الأفضل)
        if (function_exists('proc_open')) {
            try {
                $desc = [
                    0 => ['pipe', 'r'],
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ];
                $proc = proc_open([$bin, $script], $desc, $pipes);
                if (is_resource($proc)) {
                    fwrite($pipes[0], $jsonInput);
                    fclose($pipes[0]);
                    $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
                    $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
                    $code = proc_close($proc);
                    if ($code === 0 && $out) return ['success'=>true, 'output'=>trim($out)];
                    return ['success'=>false, 'error'=>$err ?: 'Exit code: '.$code];
                }
            } catch (\Exception $e) {}
        }

        // طريقة 2: shell_exec مع temp file
        if (function_exists('shell_exec')) {
            try {
                $tmpIn = tempnam(sys_get_temp_dir(), 'zatca_in_');
                file_put_contents($tmpIn, $jsonInput);
                $out = @shell_exec($bin . ' ' . escapeshellarg($script) . ' < ' . escapeshellarg($tmpIn) . ' 2>&1');
                @unlink($tmpIn);
                if ($out) return ['success'=>true, 'output'=>trim($out)];
            } catch (\Exception $e) {}
        }

        return ['success'=>false, 'error'=>'لا يمكن تشغيل Python - تحقق من صلاحيات السيرفر'];
    }

    // ===== 2. Compliance CSID =====

    public function requestComplianceCSID(string $otp): array {
        $csr = ($this->getSettings())['zatca_csr'] ?? '';
        if (!$csr) return ['success'=>false, 'error'=>'لا يوجد CSR - يرجى توليد CSR أولاً'];

        $res = $this->apiCall('POST', '/compliance', ['csr'=>$csr], null, null, $otp);

        if ($res['success']) {
            $b = $res['body'];
            $this->saveSettings([
                'zatca_compliance_csid'       => $b['binarySecurityToken'] ?? '',
                'zatca_compliance_secret'     => $b['secret'] ?? '',
                'zatca_compliance_request_id' => $b['requestID'] ?? '',
                'zatca_otp'                   => $otp,
                'zatca_onboarding_status'     => 'compliance_obtained',
                'zatca_last_error'            => null,
            ]);
            return ['success'=>true, 'data'=>$b];
        }

        $this->saveSettings([
            'zatca_last_error'      => $res['error'] ?? 'HTTP '.$res['http_code'],
            'zatca_last_error_step' => 'compliance',
            'zatca_onboarding_status' => 'error',
        ]);
        return $res;
    }

    // ===== 3. Compliance Check =====

    public function complianceCheck(string $xmlB64, string $hash, string $uuid): array {
        $s = $this->getSettings();
        $csid = $s['zatca_compliance_csid'] ?? '';
        $sec  = $s['zatca_compliance_secret'] ?? '';
        if (!$csid || !$sec) return ['success'=>false, 'error'=>'لا يوجد Compliance CSID'];

        $res = $this->apiCall('POST', '/compliance/invoices',
            ['invoiceHash'=>$hash, 'uuid'=>$uuid, 'invoice'=>$xmlB64],
            $csid, $sec);

        if ($res['success'])
            $this->saveSettings(['zatca_onboarding_status'=>'compliance_checked']);

        return $res;
    }

    // ===== 4. Production CSID =====

    public function requestProductionCSID(): array {
        $s   = $this->getSettings();
        $rid  = $s['zatca_compliance_request_id'] ?? '';
        $csid = $s['zatca_compliance_csid'] ?? '';
        $sec  = $s['zatca_compliance_secret'] ?? '';

        if (!$rid || !$csid || !$sec)
            return ['success'=>false, 'error'=>'بيانات Compliance غير مكتملة'];

        $res = $this->apiCall('POST', '/production/csids',
            ['compliance_request_id'=>$rid], $csid, $sec);

        if ($res['success']) {
            $b = $res['body'];
            $this->saveSettings([
                'zatca_production_csid'   => $b['binarySecurityToken'] ?? '',
                'zatca_production_secret' => $b['secret'] ?? '',
                'zatca_onboarding_status' => 'active',
                'zatca_last_error'        => null,
            ]);
            return ['success'=>true, 'data'=>$b];
        }

        $this->saveSettings([
            'zatca_last_error'        => $res['error'] ?? 'HTTP '.$res['http_code'],
            'zatca_last_error_step'   => 'production',
            'zatca_onboarding_status' => 'error',
        ]);
        return $res;
    }

    // ===== 5. Reporting API (B2C) =====

    public function reportInvoice(string $xmlB64, string $hash, string $uuid): array {
        $s    = $this->getSettings();
        $csid = $s['zatca_production_csid'] ?? '';
        $sec  = $s['zatca_production_secret'] ?? '';
        if (!$csid || !$sec) return ['success'=>false, 'error'=>'لا يوجد Production CSID'];
        return $this->apiCall('POST', '/invoices/reporting/single',
            ['invoiceHash'=>$hash, 'uuid'=>$uuid, 'invoice'=>$xmlB64], $csid, $sec);
    }

    // ===== 6. Clearance API (B2B) =====

    public function clearInvoice(string $xmlB64, string $hash, string $uuid): array {
        $s    = $this->getSettings();
        $csid = $s['zatca_production_csid'] ?? '';
        $sec  = $s['zatca_production_secret'] ?? '';
        if (!$csid || !$sec) return ['success'=>false, 'error'=>'لا يوجد Production CSID'];
        return $this->apiCall('POST', '/invoices/clearance/single',
            ['invoiceHash'=>$hash, 'uuid'=>$uuid, 'invoice'=>$xmlB64], $csid, $sec);
    }

    // ===== 7. بناء XML UBL 2.1 + التوقيع الرقمي =====

    public function buildInvoiceXML(array $invoice, array $items, array $company, array $customer=[], string $typeCode='388', string $subType='02'): array {
        $uuid    = $invoice['uuid'] ?? generateInvoiceUUID();
        $invNo   = $invoice['invoice_number'] ?? '';
        $invDate = $invoice['invoice_date'] ?? date('Y-m-d');
        $invTime = date('H:i:s');
        $cur     = $company['currency'] ?? 'SAR';
        $vatRate = floatval($company['vat_rate'] ?? 15);

        $sName = $company['company_name']??''; $sTax=$company['tax_number']??''; $sCR=$company['cr_number']??'';
        $sStreet=$company['street']??$company['address']??''; $sCity=$company['city']??'';
        $sDistrict=$company['district']??''; $sBuilding=$company['building_number']??''; $sPostal=$company['postal_code']??'';

        $bName=$customer['name']??''; $bTax=$customer['tax_number']??'';
        $bStreet=$customer['address']??''; $bCity=$customer['city']??''; $bCountry=$customer['country']??'SA';

        $txCode = $subType.'00000';
        $counter = $this->getAndIncrementCounter();
        $prevHash = $this->getPreviousHash();

        $subtotal=0; $totalVat=0; $totalDisc=0;
        foreach ($items as &$it) {
            $qty=floatval($it['quantity']??1); $up=floatval($it['unit_price']??0); $disc=floatval($it['discount_amount']??0);
            $ln=round(($qty*$up)-$disc,2); $vr=floatval($it['vat_rate']??$vatRate);
            $lv=round($ln*$vr/100,2);
            $it['_ln']=$ln; $it['_lv']=$lv; $it['_lt']=round($ln+$lv,2); $it['_vr']=$vr; $it['_vc']=($vr>0)?'S':'E';
            $subtotal+=$ln; $totalVat+=$lv; $totalDisc+=$disc;
        } unset($it);

        $taxExcl=round($subtotal,2); $taxIncl=round($subtotal+$totalVat,2);
        $payable=round($taxIncl-floatval($invoice['prepaid_amount']??0),2);
        $pmMap=['cash'=>'10','card'=>'48','transfer'=>'42','credit'=>'30','insurance'=>'48','check'=>'20'];
        $pmCode=$pmMap[$invoice['payment_type']??'cash']??'10';

        $x='<?xml version="1.0" encoding="UTF-8"?>'."\n";
        $x.='<Invoice xmlns="'.self::NS_INVOICE.'" xmlns:cac="'.self::NS_CAC.'" xmlns:cbc="'.self::NS_CBC.'" xmlns:ext="'.self::NS_EXT.'">'."\n";

        // UBL Extensions
        $x.='<ext:UBLExtensions><ext:UBLExtension><ext:ExtensionURI>urn:oasis:names:specification:ubl:dsig:enveloped:xades</ext:ExtensionURI><ext:ExtensionContent>'
           .'<sig:UBLDocumentSignatures xmlns:sig="'.self::NS_SIG.'" xmlns:sac="'.self::NS_SAC.'" xmlns:sbc="'.self::NS_SBC.'">'
           .'<sac:SignatureInformation>'
           .'<cbc:ID>urn:oasis:names:specification:ubl:signature:1</cbc:ID>'
           .'<sbc:ReferencedSignatureID>urn:oasis:names:specification:ubl:signature:Invoice</sbc:ReferencedSignatureID>'
           .'<ds:Signature xmlns:ds="'.self::NS_DS.'" Id="signature">'
           .'<ds:SignedInfo>'
           .'<ds:CanonicalizationMethod Algorithm="http://www.w3.org/2006/12/xml-c14n11"/>'
           .'<ds:SignatureMethod Algorithm="http://www.w3.org/2001/04/xmldsig-more#ecdsa-sha256"/>'
           .'<ds:Reference Id="invoiceSignedData" URI="">'
           .'<ds:Transforms>'
           .'<ds:Transform Algorithm="http://www.w3.org/TR/1999/REC-xpath-19991116"><ds:XPath>not(//ancestor-or-self::ext:UBLExtensions)</ds:XPath></ds:Transform>'
           .'<ds:Transform Algorithm="http://www.w3.org/TR/1999/REC-xpath-19991116"><ds:XPath>not(//ancestor-or-self::cac:Signature)</ds:XPath></ds:Transform>'
           .'<ds:Transform Algorithm="http://www.w3.org/TR/1999/REC-xpath-19991116"><ds:XPath>not(//ancestor-or-self::cac:AdditionalDocumentReference[cbc:ID=\'QR\'])</ds:XPath></ds:Transform>'
           .'<ds:Transform Algorithm="http://www.w3.org/2006/12/xml-c14n11"/>'
           .'</ds:Transforms>'
           .'<ds:DigestMethod Algorithm="http://www.w3.org/2001/04/xmlenc#sha256"/>'
           .'<ds:DigestValue>INVOICE_DIGEST_PLACEHOLDER</ds:DigestValue>'
           .'</ds:Reference>'
           .'<ds:Reference Type="http://www.w3.org/2000/09/xmldsig#SignatureProperties" URI="#xadesSignedProperties">'
           .'<ds:DigestMethod Algorithm="http://www.w3.org/2001/04/xmlenc#sha256"/>'
           .'<ds:DigestValue>PROPS_DIGEST_PLACEHOLDER</ds:DigestValue>'
           .'</ds:Reference>'
           .'</ds:SignedInfo>'
           .'<ds:SignatureValue>SIGNATURE_VALUE_PLACEHOLDER</ds:SignatureValue>'
           .'<ds:KeyInfo><ds:X509Data><ds:X509Certificate>CERTIFICATE_PLACEHOLDER</ds:X509Certificate></ds:X509Data></ds:KeyInfo>'
           .'<ds:Object>'
           .'<xades:QualifyingProperties xmlns:xades="'.self::NS_XADES.'" Target="signature">'
           .'<xades:SignedProperties Id="xadesSignedProperties">'
           .'<xades:SignedSignatureProperties>'
           .'<xades:SigningTime>SIGNING_TIME_PLACEHOLDER</xades:SigningTime>'
           .'<xades:SigningCertificate><xades:Cert>'
           .'<xades:CertDigest><ds:DigestMethod Algorithm="http://www.w3.org/2001/04/xmlenc#sha256"/><ds:DigestValue>CERT_DIGEST_PLACEHOLDER</ds:DigestValue></xades:CertDigest>'
           .'<xades:IssuerSerial><ds:X509IssuerName>ISSUER_NAME_PLACEHOLDER</ds:X509IssuerName><ds:X509SerialNumber>SERIAL_NUMBER_PLACEHOLDER</ds:X509SerialNumber></xades:IssuerSerial>'
           .'</xades:Cert></xades:SigningCertificate>'
           .'</xades:SignedSignatureProperties>'
           .'</xades:SignedProperties>'
           .'</xades:QualifyingProperties>'
           .'</ds:Object>'
           .'</ds:Signature>'
           .'</sac:SignatureInformation>'
           .'</sig:UBLDocumentSignatures>'
           .'</ext:ExtensionContent></ext:UBLExtension></ext:UBLExtensions>'."\n";

        $x.='<cbc:ProfileID>reporting:1.0</cbc:ProfileID>'."\n";
        $x.='<cbc:ID>'.htmlspecialchars($invNo).'</cbc:ID>'."\n";
        $x.='<cbc:UUID>'.$uuid.'</cbc:UUID>'."\n";
        $x.='<cbc:IssueDate>'.$invDate.'</cbc:IssueDate>'."\n";
        $x.='<cbc:IssueTime>'.$invTime.'</cbc:IssueTime>'."\n";
        $x.='<cbc:InvoiceTypeCode name="'.$txCode.'">'.$typeCode.'</cbc:InvoiceTypeCode>'."\n";
        // KSA-10: Note must appear between InvoiceTypeCode and DocumentCurrencyCode per UBL schema order
        if (in_array($typeCode,['381','383']))
            $x.='<cbc:Note>'.htmlspecialchars($invoice['note'] ?? ($typeCode==='381' ? 'Return of goods' : 'Price adjustment')).'</cbc:Note>'."\n";
        $x.='<cbc:DocumentCurrencyCode>'.$cur.'</cbc:DocumentCurrencyCode>'."\n";
        $x.='<cbc:TaxCurrencyCode>SAR</cbc:TaxCurrencyCode>'."\n";

        if (in_array($typeCode,['381','383']) && !empty($invoice['original_invoice_number']))
            $x.='<cac:BillingReference><cac:InvoiceDocumentReference><cbc:ID>'.htmlspecialchars($invoice['original_invoice_number']).'</cbc:ID></cac:InvoiceDocumentReference></cac:BillingReference>'."\n";

        $x.='<cac:AdditionalDocumentReference><cbc:ID>ICV</cbc:ID><cbc:UUID>'.$counter.'</cbc:UUID></cac:AdditionalDocumentReference>'."\n";
        $x.='<cac:AdditionalDocumentReference><cbc:ID>PIH</cbc:ID><cac:Attachment><cbc:EmbeddedDocumentBinaryObject mimeCode="text/plain">'.$prevHash.'</cbc:EmbeddedDocumentBinaryObject></cac:Attachment></cac:AdditionalDocumentReference>'."\n";
        $x.='<cac:AdditionalDocumentReference><cbc:ID>QR</cbc:ID><cac:Attachment><cbc:EmbeddedDocumentBinaryObject mimeCode="text/plain">QR_PLACEHOLDER</cbc:EmbeddedDocumentBinaryObject></cac:Attachment></cac:AdditionalDocumentReference>'."\n";
        $x.='<cac:Signature><cbc:ID>urn:oasis:names:specification:ubl:signature:Invoice</cbc:ID><cbc:SignatureMethod>urn:oasis:names:specification:ubl:dsig:enveloped:xades</cbc:SignatureMethod></cac:Signature>'."\n";

        // Seller
        $x.='<cac:AccountingSupplierParty><cac:Party>'."\n";
        if ($sCR) $x.='<cac:PartyIdentification><cbc:ID schemeID="CRN">'.htmlspecialchars($sCR).'</cbc:ID></cac:PartyIdentification>'."\n";
        $x.='<cac:PostalAddress><cbc:StreetName>'.htmlspecialchars($sStreet).'</cbc:StreetName>';
        if ($sBuilding) $x.='<cbc:BuildingNumber>'.htmlspecialchars($sBuilding).'</cbc:BuildingNumber>';
        if ($sDistrict) $x.='<cbc:CitySubdivisionName>'.htmlspecialchars($sDistrict).'</cbc:CitySubdivisionName>';
        $x.='<cbc:CityName>'.htmlspecialchars($sCity).'</cbc:CityName>';
        if ($sPostal) $x.='<cbc:PostalZone>'.htmlspecialchars($sPostal).'</cbc:PostalZone>';
        $x.='<cac:Country><cbc:IdentificationCode>SA</cbc:IdentificationCode></cac:Country></cac:PostalAddress>'."\n";
        $x.='<cac:PartyTaxScheme><cbc:CompanyID>'.htmlspecialchars($sTax).'</cbc:CompanyID><cac:TaxScheme><cbc:ID>VAT</cbc:ID></cac:TaxScheme></cac:PartyTaxScheme>'."\n";
        $x.='<cac:PartyLegalEntity><cbc:RegistrationName>'.htmlspecialchars($sName).'</cbc:RegistrationName></cac:PartyLegalEntity>'."\n";
        $x.='</cac:Party></cac:AccountingSupplierParty>'."\n";

        // Buyer
        $x.='<cac:AccountingCustomerParty><cac:Party>'."\n";
        $x.='<cac:PostalAddress>';
        if ($bStreet) $x.='<cbc:StreetName>'.htmlspecialchars($bStreet).'</cbc:StreetName>';
        if ($bCity)   $x.='<cbc:CityName>'.htmlspecialchars($bCity).'</cbc:CityName>';
        $x.='<cac:Country><cbc:IdentificationCode>'.$bCountry.'</cbc:IdentificationCode></cac:Country></cac:PostalAddress>'."\n";
        if ($bTax)  $x.='<cac:PartyTaxScheme><cbc:CompanyID>'.htmlspecialchars($bTax).'</cbc:CompanyID><cac:TaxScheme><cbc:ID>VAT</cbc:ID></cac:TaxScheme></cac:PartyTaxScheme>'."\n";
        if ($bName) $x.='<cac:PartyLegalEntity><cbc:RegistrationName>'.htmlspecialchars($bName).'</cbc:RegistrationName></cac:PartyLegalEntity>'."\n";
        $x.='</cac:Party></cac:AccountingCustomerParty>'."\n";

        $x.='<cac:Delivery><cbc:ActualDeliveryDate>'.$invDate.'</cbc:ActualDeliveryDate></cac:Delivery>'."\n";
        // KSA-10: credit/debit notes (381/383) must have InstructionNote (reason) in PaymentMeans
        $x.='<cac:PaymentMeans><cbc:PaymentMeansCode>'.$pmCode.'</cbc:PaymentMeansCode>';
        if (in_array($typeCode,['381','383']))
            $x.='<cbc:InstructionNote>'.htmlspecialchars($invoice['note'] ?? ($typeCode==='381' ? 'Return of goods' : 'Price adjustment')).'</cbc:InstructionNote>';
        $x.='</cac:PaymentMeans>'."\n";

        // Tax Total
        $vg=[];
        foreach ($items as $it) {
            $k=$it['_vc'].'_'.$it['_vr'];
            if (!isset($vg[$k])) $vg[$k]=['taxable'=>0,'tax'=>0,'rate'=>$it['_vr'],'code'=>$it['_vc']];
            $vg[$k]['taxable']+=$it['_ln']; $vg[$k]['tax']+=$it['_lv'];
        }
        $f2=function($v){return number_format($v,2,'.','');};

        $x.='<cac:TaxTotal><cbc:TaxAmount currencyID="'.$cur.'">'.$f2($totalVat).'</cbc:TaxAmount>'."\n";
        foreach ($vg as $g) {
            $x.='<cac:TaxSubtotal><cbc:TaxableAmount currencyID="'.$cur.'">'.$f2($g['taxable']).'</cbc:TaxableAmount>';
            $x.='<cbc:TaxAmount currencyID="'.$cur.'">'.$f2($g['tax']).'</cbc:TaxAmount>';
            $x.='<cac:TaxCategory><cbc:ID>'.$g['code'].'</cbc:ID><cbc:Percent>'.$f2($g['rate']).'</cbc:Percent>';
            if ($g['code']==='E') $x.='<cbc:TaxExemptionReasonCode>VATEX-SA-35</cbc:TaxExemptionReasonCode><cbc:TaxExemptionReason>Medicines and medical equipment</cbc:TaxExemptionReason>';
            $x.='<cac:TaxScheme><cbc:ID>VAT</cbc:ID></cac:TaxScheme></cac:TaxCategory></cac:TaxSubtotal>'."\n";
        }
        $x.='</cac:TaxTotal>'."\n";
        $x.='<cac:TaxTotal><cbc:TaxAmount currencyID="SAR">'.$f2($totalVat).'</cbc:TaxAmount></cac:TaxTotal>'."\n";

        // Legal Monetary Total
        $x.='<cac:LegalMonetaryTotal>';
        $x.='<cbc:LineExtensionAmount currencyID="'.$cur.'">'.$f2($subtotal).'</cbc:LineExtensionAmount>';
        $x.='<cbc:TaxExclusiveAmount currencyID="'.$cur.'">'.$f2($taxExcl).'</cbc:TaxExclusiveAmount>';
        $x.='<cbc:TaxInclusiveAmount currencyID="'.$cur.'">'.$f2($taxIncl).'</cbc:TaxInclusiveAmount>';
        if ($totalDisc>0) $x.='<cbc:AllowanceTotalAmount currencyID="'.$cur.'">'.$f2($totalDisc).'</cbc:AllowanceTotalAmount>';
        $x.='<cbc:PayableAmount currencyID="'.$cur.'">'.$f2($payable).'</cbc:PayableAmount>';
        $x.='</cac:LegalMonetaryTotal>'."\n";

        // Invoice Lines
        $lid=0;
        foreach ($items as $it) {
            $lid++; $qty=floatval($it['quantity']??1); $up=floatval($it['unit_price']??0);
            $nm=$it['product_name']??$it['name']??'';
            $x.='<cac:InvoiceLine><cbc:ID>'.$lid.'</cbc:ID>';
            $x.='<cbc:InvoicedQuantity unitCode="PCE">'.$f2($qty).'</cbc:InvoicedQuantity>';
            $x.='<cbc:LineExtensionAmount currencyID="'.$cur.'">'.$f2($it['_ln']).'</cbc:LineExtensionAmount>';
            $x.='<cac:TaxTotal><cbc:TaxAmount currencyID="'.$cur.'">'.$f2($it['_lv']).'</cbc:TaxAmount>';
            $x.='<cbc:RoundingAmount currencyID="'.$cur.'">'.$f2($it['_lt']).'</cbc:RoundingAmount></cac:TaxTotal>';
            $x.='<cac:Item><cbc:Name>'.htmlspecialchars($nm).'</cbc:Name>';
            $x.='<cac:ClassifiedTaxCategory><cbc:ID>'.$it['_vc'].'</cbc:ID><cbc:Percent>'.$f2($it['_vr']).'</cbc:Percent>';
            $x.='<cac:TaxScheme><cbc:ID>VAT</cbc:ID></cac:TaxScheme></cac:ClassifiedTaxCategory></cac:Item>';
            $x.='<cac:Price><cbc:PriceAmount currencyID="'.$cur.'">'.$f2($up).'</cbc:PriceAmount>';
            $x.='<cbc:BaseQuantity unitCode="PCE">1</cbc:BaseQuantity></cac:Price>';
            $x.='</cac:InvoiceLine>'."\n";
        }
        $x.='</Invoice>';

        // التوقيع
        $signResult = $this->signInvoiceXML($x);
        if (!$signResult['success']) {
            // DO NOT submit with dummy hashes — ZATCA will always reject them.
            // Return the signing error so the caller knows what went wrong.
            return ['success'=>false, 'error'=>'فشل التوقيع الرقمي: '.($signResult['error']??'Unknown signing error')];
        }

        $signedXml = $signResult['xml'];
        $hash      = $signResult['hash'];
        $signature = $signResult['signature'] ?? '';
        $publicKey = $signResult['public_key'] ?? '';
        $certSig   = $signResult['cert_signature'] ?? '';

        // ZATCA compliance API QR format:
        // Tag 6: invoice hash (base64 string, text)
        // Tag 7: ECDSA invoice signature (base64-encoded DER, as ASCII text)
        // Tag 8: EC public key (SPKI DER binary bytes)
        // Tag 9: X.509 certificate signature (DER binary — CA's signature over TBS cert)
        $qrSig    = $signature ? base64_encode($signature) : '';
        $qr = $this->buildQRCode($sName,$sTax,$invDate.'T'.$invTime,$taxIncl,$totalVat,$hash,$qrSig,$publicKey,$certSig);
        $signedXml = str_replace('QR_PLACEHOLDER',$qr,$signedXml);
        $this->updatePreviousHash($hash,$counter);

        return ['success'=>true,'xml'=>$signedXml,'hash'=>$hash,'uuid'=>$uuid,'qr'=>$qr,'counter'=>$counter,'previousHash'=>$prevHash];
    }

    // ===== 8. Invoice Hash =====

    public function computeInvoiceHash(string $xml): string {
        try {
            $doc = new \DOMDocument('1.0','UTF-8');
            $doc->preserveWhiteSpace = true;
            $doc->loadXML($xml);
            $xpath = new \DOMXPath($doc);
            $xpath->registerNamespace('ext',self::NS_EXT);
            $xpath->registerNamespace('cac',self::NS_CAC);
            $xpath->registerNamespace('cbc',self::NS_CBC);

            foreach ($xpath->query('//ext:UBLExtensions') as $node)
                $node->parentNode->removeChild($node);
            foreach ($xpath->query("//cac:AdditionalDocumentReference[cbc:ID[normalize-space(text())='QR']]") as $node)
                $node->parentNode->removeChild($node);
            foreach ($xpath->query('//cac:Signature') as $node)
                $node->parentNode->removeChild($node);

            $canonicalized = $doc->documentElement->C14N(false,false);
            return base64_encode(hash('sha256',$canonicalized,true));
        } catch (\Exception $e) {
            $c = preg_replace('/<\?xml[^?]*\?>/','', $xml);
            $c = preg_replace('/<ext:UBLExtensions>.*?<\/ext:UBLExtensions>/s','',$c);
            $c = preg_replace('/<cac:AdditionalDocumentReference>\s*<cbc:ID>QR<\/cbc:ID>.*?<\/cac:AdditionalDocumentReference>/s','',$c);
            $c = preg_replace('/<cac:Signature>.*?<\/cac:Signature>/s','',$c);
            return base64_encode(hash('sha256',trim($c),true));
        }
    }

    // ===== 8.5 التوقيع الرقمي =====

    public function signInvoiceXML(string $xml): array {
        $settings      = $this->getSettings();
        $privateKeyPem = $settings['zatca_private_key'] ?? '';
        $certB64       = $settings['zatca_production_csid'] ?? $settings['zatca_compliance_csid'] ?? '';

        if (!$privateKeyPem || !$certB64)
            return ['success'=>false, 'error'=>'Missing private key or certificate'];

        try {
            // ZATCA binarySecurityToken may be:
            //   A) base64(base64(DER)) — double-encoded
            //   B) base64(DER) — single-encoded (already PEM body)
            // Try double-decode first, fallback to single.
            $decoded = base64_decode($certB64, true);
            $certB64pem = null;
            $certDer    = null;

            if ($decoded !== false) {
                // Check if decoded result looks like a base64 string (PEM body)
                $cleaned = preg_replace('/\s+/', '', $decoded);
                if (preg_match('/^[A-Za-z0-9+\/]+=*$/', $cleaned) && strlen($cleaned) > 100) {
                    // Double-encoded: decoded is the PEM body
                    $certB64pem = $cleaned;
                    $certDer    = base64_decode($certB64pem);
                }
            }

            if ($certB64pem === null) {
                // Single-encoded: $certB64 IS already the PEM body
                $certB64pem = preg_replace('/\s+/', '', $certB64);
                $certDer    = base64_decode($certB64pem);
            }

            $certPem = "-----BEGIN CERTIFICATE-----\n".chunk_split($certB64pem,64,"\n")."-----END CERTIFICATE-----";
            $certResource = openssl_x509_read($certPem);
            if (!$certResource)
                return ['success'=>false, 'error'=>'Invalid certificate: '.openssl_error_string()];

            $certData    = openssl_x509_parse($certResource);
            $issuerName  = $this->formatIssuerName($certData['issuer'] ?? []);
            $serialNumber = $certData['serialNumber'] ?? '0';
            // PHP may return serial as hex (0x...) for large numbers; ZATCA schema requires decimal integer
            if (preg_match('/^0x([0-9a-fA-F]+)$/i', $serialNumber, $hexM)) {
                $serialNumber = extension_loaded('gmp')
                    ? gmp_strval(gmp_init($hexM[1], 16))
                    : bcadd(base_convert($hexM[1], 16, 10), '0'); // fallback (may lose precision)
            }

            $invoiceHash = $this->computeInvoiceHash($xml);
            // ZATCA cert hash = base64(SHA256_raw_binary(DER)) per XML-DSig / XAdES standard
            $certHash    = base64_encode(hash('sha256',$certDer,true));
            $signingTime = gmdate('Y-m-d\TH:i:s');

            $xml = str_replace('SIGNING_TIME_PLACEHOLDER',  $signingTime,  $xml);
            $xml = str_replace('CERT_DIGEST_PLACEHOLDER',   $certHash,     $xml);
            $xml = str_replace('ISSUER_NAME_PLACEHOLDER',   htmlspecialchars($issuerName), $xml);
            $xml = str_replace('SERIAL_NUMBER_PLACEHOLDER', $serialNumber, $xml);
            // ds:X509Certificate must contain the base64-encoded DER (PEM body, not the outer double-b64)
            $xml = str_replace('CERTIFICATE_PLACEHOLDER',   $certB64pem,   $xml);
            $xml = str_replace('INVOICE_DIGEST_PLACEHOLDER',$invoiceHash,  $xml);

            $propsHash = $this->computeSignedPropertiesHash($xml);
            $xml = str_replace('PROPS_DIGEST_PLACEHOLDER', $propsHash, $xml);

            // XML-DSig: sign the canonical ds:SignedInfo element (NOT the invoice body).
            // secp256k1 keys are not loadable via PHP OpenSSL 3.x — use CLI.
            $signedInfoCanonical = $this->canonicalizeSignedInfo($xml);
            if (!$signedInfoCanonical) {
                // fallback: try canonical invoice bytes (may fail ZATCA validation)
                $signedInfoCanonical = $this->getCanonicalInvoiceBytes($xml);
                if (!$signedInfoCanonical) $signedInfoCanonical = $xml;
            }

            $signature = $this->signWithCli($signedInfoCanonical, $privateKeyPem);
            if (!$signature)
                return ['success'=>false, 'error'=>'CLI signing failed — check openssl binary and key format'];

            $xml = str_replace('SIGNATURE_VALUE_PLACEHOLDER', base64_encode($signature), $xml);

            $finalHash = $this->computeInvoiceHash($xml);

            // QR tag 7 must be the SAME raw bytes as ds:SignatureValue (ZATCA validates they match)
            // QR tag 8: SubjectPublicKeyInfo DER bytes (what openssl x509 -pubkey extracts)
            $pubKeyDetails = openssl_pkey_get_details(openssl_pkey_get_public($certPem));
            $publicKeyDer  = '';
            if (!empty($pubKeyDetails['key'])) {
                $pkPem = $pubKeyDetails['key'];
                $pkPem = preg_replace('/-----[^-]+-----|\s+/', '', $pkPem);
                $publicKeyDer = base64_decode($pkPem); // 88-byte SPKI DER
            }

            // QR tag 9: X.509 certificate's own signature value (CA's signature over TBS cert)
            // This is the BIT STRING at the end of the DER-encoded X.509 certificate
            $certSigBytes = $this->extractCertSignature($certDer);

            return [
                'success'        => true,
                'xml'            => $xml,
                'hash'           => $finalHash,
                'signature'      => $signature,
                'public_key'     => $publicKeyDer,
                'cert_signature' => $certSigBytes,
            ];
        } catch (\Exception $e) {
            return ['success'=>false, 'error'=>$e->getMessage()];
        }
    }

    private function computeSignedPropertiesHash(string $xml): string {
        // Use EXCLUSIVE C14N — only includes visibly-utilized namespaces (xades, ds),
        // not all ancestor namespaces (Invoice, cac, cbc, ext, etc.)
        try {
            $doc = new \DOMDocument('1.0', 'UTF-8');
            $doc->preserveWhiteSpace = true;
            $doc->loadXML($xml);
            $xpath = new \DOMXPath($doc);
            $xpath->registerNamespace('xades', self::NS_XADES);
            $nodes = $xpath->query('//xades:SignedProperties[@Id="xadesSignedProperties"]');
            if ($nodes->length > 0) {
                $canonical = $nodes->item(0)->C14N(true, false);
                return base64_encode(hash('sha256', $canonical, true));
            }
        } catch (\Exception $e) {}
        return base64_encode(hash('sha256', '', true));
    }

    private function formatIssuerName(array $issuer): string {
        $parts=[];
        foreach (['CN'=>'CN','O'=>'O','OU'=>'OU','C'=>'C','L'=>'L','ST'=>'ST','serialNumber'=>'serialNumber'] as $key=>$label) {
            if (!empty($issuer[$key])) {
                $val=is_array($issuer[$key])?$issuer[$key][0]:$issuer[$key];
                $parts[]="$label=$val";
            }
        }
        return implode(', ',$parts);
    }

    // ===== 9. QR Code (TLV) =====

    public function buildQRCode(string $seller, string $tax, string $ts, float $total, float $vat, string $hash, string $sig='', string $pubKey='', string $certSig=''): string {
        $tlv='';
        $enc=function(int $tag, string $val) {
            $len=strlen($val); $r=chr($tag);
            if ($len<=127) $r.=chr($len);
            elseif ($len<=255) $r.=chr(0x81).chr($len);
            else $r.=chr(0x82).chr(($len>>8)&0xFF).chr($len&0xFF);
            return $r.$val;
        };
        $tlv.=$enc(1,$seller).$enc(2,$tax).$enc(3,$ts).$enc(4,number_format($total,2,'.','')).$enc(5,number_format($vat,2,'.',''));
        if ($hash)   $tlv.=$enc(6,$hash);
        if ($sig)    $tlv.=$enc(7,$sig);
        if ($pubKey) $tlv.=$enc(8,$pubKey);
        if ($certSig)$tlv.=$enc(9,$certSig);
        return base64_encode($tlv);
    }

    // ===== 10. Invoice Counter =====

    private function getAndIncrementCounter(): int {
        $bid=$this->branchId??1;
        try {
            $this->pdo->beginTransaction();
            $s=$this->pdo->prepare("SELECT counter_value FROM zatca_invoice_counters WHERE tenant_id=? AND branch_id=? FOR UPDATE");
            $s->execute([$this->tenantId,$bid]); $r=$s->fetch();
            if ($r) {
                $n=intval($r['counter_value'])+1;
                $this->pdo->prepare("UPDATE zatca_invoice_counters SET counter_value=? WHERE tenant_id=? AND branch_id=?")->execute([$n,$this->tenantId,$bid]);
                $this->pdo->commit(); return $n;
            }
            $this->pdo->prepare("INSERT INTO zatca_invoice_counters (tenant_id,branch_id,counter_value) VALUES (?,?,1)")->execute([$this->tenantId,$bid]);
            $this->pdo->commit(); return 1;
        } catch (\Exception $e) {
            try { $this->pdo->rollBack(); } catch (\Exception $e2) {}
            return 1;
        }
    }

    private function getPreviousHash(): string {
        $bid=$this->branchId??1;
        $default='NWZlY2ViNjZmZmM4NmYzOGQ5NTI3ODZjNmQ2OTZjNzljMmRiYzIzOWRkNGU5MWI0NjcyOWQ3M2EyN2ZiNTdlOQ==';
        try {
            $s=$this->pdo->prepare("SELECT last_invoice_hash FROM zatca_invoice_counters WHERE tenant_id=? AND branch_id=?");
            $s->execute([$this->tenantId,$bid]); $r=$s->fetch();
            return $r['last_invoice_hash']??$default;
        } catch (\Exception $e) { return $default; }
    }

    private function updatePreviousHash(string $hash, int $counter): void {
        $bid=$this->branchId??1;
        try {
            $this->pdo->prepare("UPDATE zatca_invoice_counters SET last_invoice_hash=?,counter_value=? WHERE tenant_id=? AND branch_id=?")->execute([$hash,$counter,$this->tenantId,$bid]);
        } catch (\Exception $e) {}
    }

    // ===== 11. Log =====

    public function logInvoiceSubmission(int $invId, string $type, array $res, string $xml): int {
        try {
            $this->pdo->prepare("INSERT INTO e_invoice_logs (tenant_id,invoice_id,invoice_type,uuid,invoice_hash,qr_code,submission_status,submission_date,response_code,response_message,raw_request,raw_response,xml_content) VALUES(?,?,?,?,?,?,?,NOW(),?,?,?,?,?)")
            ->execute([$this->tenantId,$invId,$type,$res['uuid']??'',$res['hash']??'',$res['qr']??'',$res['status']??'pending',$res['response_code']??'',$res['response_message']??'',$res['raw_request']??'',$res['raw_response']??'',$xml]);
            return intval($this->pdo->lastInsertId());
        } catch (\Exception $e) { return 0; }
    }

    // ===== 12. Submit Invoice =====

    public function submitSalesInvoice(int $invoiceId): array {
        if (!$this->isEnabled()) return ['success'=>false,'error'=>'ZATCA غير مفعّل'];
        $tid=$this->tenantId;
        $inv=$this->pdo->prepare("SELECT * FROM sales_invoices WHERE id=? AND tenant_id=?"); $inv->execute([$invoiceId,$tid]); $invoice=$inv->fetch();
        if (!$invoice) return ['success'=>false,'error'=>'الفاتورة غير موجودة'];
        $itm=$this->pdo->prepare("SELECT * FROM sales_invoice_items WHERE invoice_id=?"); $itm->execute([$invoiceId]); $items=$itm->fetchAll();
        if (!$items) return ['success'=>false,'error'=>'لا يوجد أصناف في الفاتورة'];
        $company=[]; try { $c=$this->pdo->prepare("SELECT * FROM company_settings WHERE tenant_id=?"); $c->execute([$tid]); $company=$c->fetch()?:[]; } catch(\Exception $e){}
        $customer=[]; if ($invoice['customer_id']) { try { $c=$this->pdo->prepare("SELECT * FROM customers WHERE id=? AND tenant_id=?"); $c->execute([$invoice['customer_id'],$tid]); $customer=$c->fetch()?:[]; } catch(\Exception $e){} }

        $xmlR=$this->buildInvoiceXML($invoice,$items,$company,$customer,'388','02');
        if (!$xmlR['success']) return $xmlR;

        $xmlB64=base64_encode($xmlR['xml']);
        $apiR=$this->reportInvoice($xmlB64,$xmlR['hash'],$xmlR['uuid']);

        $st='error';
        if ($apiR['success']) { $hc=$apiR['http_code']??200; $st=($hc==200)?'reported':(($hc==202)?'accepted_with_warnings':'error'); }
        elseif (($apiR['http_code']??0)==400) $st='rejected';

        try { $this->pdo->prepare("UPDATE sales_invoices SET uuid=?,invoice_hash=?,qr_code=?,xml_content=?,zatca_status=?,zatca_response=?,zatca_warnings=?,zatca_errors=?,zatca_sent_at=NOW() WHERE id=? AND tenant_id=?")
            ->execute([$xmlR['uuid'],$xmlR['hash'],$xmlR['qr'],$xmlR['xml'],$st,
                json_encode($apiR['body']??null,JSON_UNESCAPED_UNICODE),
                json_encode($apiR['body']['validationResults']['warningMessages']??null,JSON_UNESCAPED_UNICODE),
                json_encode($apiR['body']['validationResults']['errorMessages']??null,JSON_UNESCAPED_UNICODE),
                $invoiceId,$tid]); } catch(\Exception $e){}

        $this->logInvoiceSubmission($invoiceId,'sale',['uuid'=>$xmlR['uuid'],'hash'=>$xmlR['hash'],'qr'=>$xmlR['qr'],'status'=>$st,'response_code'=>$apiR['http_code']??'','response_message'=>json_encode($apiR['body']??null,JSON_UNESCAPED_UNICODE),'raw_request'=>$xmlB64,'raw_response'=>$apiR['raw_response']??''],$xmlR['xml']);

        return ['success'=>$apiR['success'],'status'=>$st,'uuid'=>$xmlR['uuid'],'hash'=>$xmlR['hash'],'qr'=>$xmlR['qr'],
            'warnings'=>$apiR['body']['validationResults']['warningMessages']??[],'errors'=>$apiR['body']['validationResults']['errorMessages']??[],'response'=>$apiR['body']??null];
    }

    // ===== 13. Compliance Checks =====

    public function runComplianceChecks(array $company): array {
        $results=[];
        $s=$this->getSettings(); $csid=$s['zatca_compliance_csid']??''; $sec=$s['zatca_compliance_secret']??'';
        if (!$csid||!$sec) return [['success'=>false,'error'=>'No compliance CSID','type_label'=>'Error']];

        $seller=['company_name'=>$company['company_name']??'Test Company','tax_number'=>$company['tax_number']??'300000000000003','cr_number'=>$company['cr_number']??'1234567890','address'=>$company['address']??'Test','street'=>$company['street']??'King Fahd Road','city'=>$company['city']??'Riyadh','district'=>$company['district']??'Al Olaya','building_number'=>$company['building_number']??'1234','postal_code'=>$company['postal_code']??'12345','currency'=>'SAR','vat_rate'=>$company['vat_rate']??15];
        // BR-CUSTOM-VALIDATION-01: buyer VAT must differ from seller VAT
        $buyer=['name'=>'Test Buyer','tax_number'=>'399999999900003','address'=>'Test Street','city'=>'Jeddah','country'=>'SA'];
        $items=[['product_name'=>'Panadol Extra 500mg','quantity'=>2,'unit_price'=>15.00,'vat_rate'=>15,'discount_amount'=>0],['product_name'=>'Vitamin C 1000mg','quantity'=>1,'unit_price'=>25.00,'vat_rate'=>15,'discount_amount'=>0]];

        $testInvoices=[
            ['typeCode'=>'388','subType'=>'01','label'=>'فاتورة ضريبية (Standard)','buyer'=>$buyer],
            ['typeCode'=>'388','subType'=>'02','label'=>'فاتورة مبسطة (Simplified)','buyer'=>[]],
            ['typeCode'=>'381','subType'=>'01','label'=>'إشعار دائن ضريبي','buyer'=>$buyer,'ref'=>'INV-TEST-001'],
            ['typeCode'=>'381','subType'=>'02','label'=>'إشعار دائن مبسط','buyer'=>[],'ref'=>'INV-TEST-002'],
            ['typeCode'=>'383','subType'=>'01','label'=>'إشعار مدين ضريبي','buyer'=>$buyer,'ref'=>'INV-TEST-003'],
            ['typeCode'=>'383','subType'=>'02','label'=>'إشعار مدين مبسط','buyer'=>[],'ref'=>'INV-TEST-004'],
        ];

        foreach ($testInvoices as $ti) {
            $inv=['invoice_number'=>'CMP-'.strtoupper(substr(md5(microtime()),0,8)),'invoice_date'=>date('Y-m-d'),'uuid'=>generateInvoiceUUID(),'payment_type'=>'cash','prepaid_amount'=>0];
            if (!empty($ti['ref'])) $inv['original_invoice_number']=$ti['ref'];
            try {
                $xmlR=$this->buildInvoiceXML($inv,$items,$seller,$ti['buyer'],$ti['typeCode'],$ti['subType']);
                if (!$xmlR['success']) { $results[]=['success'=>false,'type_label'=>$ti['label'],'error'=>$xmlR['error']??'XML build failed']; continue; }
                $xmlB64=base64_encode($xmlR['xml']);
                $apiR=$this->complianceCheck($xmlB64,$xmlR['hash'],$xmlR['uuid']);
                $results[]=['success'=>$apiR['success'],'type_label'=>$ti['label'],'message'=>$apiR['success']?'PASSED':'','error'=>$apiR['error']??'','warnings'=>$apiR['body']['validationResults']['warningMessages']??[],'errors'=>$apiR['body']['validationResults']['errorMessages']??[],'http_code'=>$apiR['http_code']??0];
                
            } catch (\Exception $e) { $results[]=['success'=>false,'type_label'=>$ti['label'],'error'=>$e->getMessage()]; }
        }
        return $results;
    }

    // ===== X.509 Certificate Signature Extractor =====
    // Returns the raw BIT STRING value (DER ECDSA signature) from the X.509 cert's outer structure.
    // The X.509 SEQUENCE has: SEQUENCE(TBS), SEQUENCE(AlgId), BIT_STRING(CertSig).

    private function extractCertSignature(string $certDer): string {
        try {
            $pos = 0;
            $readLen = function() use (&$certDer, &$pos) {
                $len = ord($certDer[$pos++]);
                if ($len & 0x80) {
                    $nb = $len & 0x7f; $len = 0;
                    for ($i = 0; $i < $nb; $i++) $len = ($len << 8) | ord($certDer[$pos++]);
                }
                return $len;
            };
            $pos++; // outer SEQUENCE tag 0x30
            $readLen(); // outer length (skip)
            // TBS SEQUENCE
            $pos++; // 0x30
            $tbsLen = $readLen();
            $pos += $tbsLen;
            // AlgId SEQUENCE
            $pos++; // 0x30
            $algLen = $readLen();
            $pos += $algLen;
            // BIT STRING 0x03
            $pos++; // tag
            $bsLen = $readLen();
            $pos++; // skip unused-bits byte (0x00)
            return substr($certDer, $pos, $bsLen - 1);
        } catch (\Throwable $e) {
            return '';
        }
    }

    // ===== DER ECDSA Signature Parser =====

    private function parseDerEcdsaSignature(string $der): array {
        $len = strlen($der);
        if ($len < 6 || ord($der[0]) !== 0x30) return ['r' => '', 's' => ''];
        $pos = 1;
        $seqLen = ord($der[$pos++]);
        if ($seqLen & 0x80) {
            $numBytes = $seqLen & 0x7f;
            $seqLen = 0;
            for ($i = 0; $i < $numBytes; $i++) $seqLen = ($seqLen << 8) | ord($der[$pos++]);
        }
        if ($pos >= $len || ord($der[$pos]) !== 0x02) return ['r' => '', 's' => ''];
        $rLen = ord($der[++$pos]); $pos++;
        $r = substr($der, $pos, $rLen); $pos += $rLen;
        if ($pos >= $len || ord($der[$pos]) !== 0x02) return ['r' => '', 's' => ''];
        $sLen = ord($der[++$pos]); $pos++;
        $s = substr($der, $pos, $sLen);
        return ['r' => $r, 's' => $s];
    }

    // ===== Canonicalize ds:SignedInfo (for XML-DSig signature) =====

    private function canonicalizeSignedInfo(string $xml): string {
        try {
            $doc = new \DOMDocument('1.0', 'UTF-8');
            $doc->preserveWhiteSpace = true;
            $doc->loadXML($xml);
            $xpath = new \DOMXPath($doc);
            $xpath->registerNamespace('ds', self::NS_DS);
            $nodes = $xpath->query('//ds:SignedInfo');
            if ($nodes->length === 0) return '';
            // C14N with exclusive=false (inclusive c14n11 as declared in CanonicalizationMethod)
            return $nodes->item(0)->C14N(false, false);
        } catch (\Exception $e) {
            return '';
        }
    }

    // ===== Canonical Invoice Bytes (for signing and QR hash) =====

    private function getCanonicalInvoiceBytes(string $xml): string {
        try {
            $doc = new \DOMDocument('1.0', 'UTF-8');
            $doc->preserveWhiteSpace = true;
            $doc->loadXML($xml);
            $xpath = new \DOMXPath($doc);
            $xpath->registerNamespace('ext', self::NS_EXT);
            $xpath->registerNamespace('cac', self::NS_CAC);
            $xpath->registerNamespace('cbc', self::NS_CBC);
            foreach ($xpath->query('//ext:UBLExtensions') as $node)
                $node->parentNode->removeChild($node);
            foreach ($xpath->query("//cac:AdditionalDocumentReference[cbc:ID[normalize-space(text())='QR']]") as $node)
                $node->parentNode->removeChild($node);
            foreach ($xpath->query('//cac:Signature') as $node)
                $node->parentNode->removeChild($node);
            return $doc->documentElement->C14N(false, false);
        } catch (\Exception $e) {
            return '';
        }
    }

    // ===== CLI Signing (secp256k1 not supported by PHP OpenSSL 3.x) =====

    private function signWithCli(string $data, string $privateKeyPem): string {
        // The SDK stores the private key as base64(DER) without PEM headers.
        // Wrap in PEM headers if not already in PEM format.
        if (strpos($privateKeyPem, '-----') === false) {
            $privateKeyPem = "-----BEGIN PRIVATE KEY-----\n" . trim($privateKeyPem) . "\n-----END PRIVATE KEY-----";
        }
        $tmpKey  = tempnam(sys_get_temp_dir(), 'zatca_k_');
        $tmpData = tempnam(sys_get_temp_dir(), 'zatca_d_');
        $tmpSig  = tempnam(sys_get_temp_dir(), 'zatca_s_');
        try {
            file_put_contents($tmpKey,  $privateKeyPem); chmod($tmpKey, 0600);
            file_put_contents($tmpData, $data);
            @shell_exec('/usr/bin/openssl dgst -sha256 -sign '.escapeshellarg($tmpKey).' -out '.escapeshellarg($tmpSig).' '.escapeshellarg($tmpData).' 2>/dev/null');
            return file_exists($tmpSig) ? (string)file_get_contents($tmpSig) : '';
        } finally {
            @unlink($tmpKey); @unlink($tmpData); @unlink($tmpSig);
        }
    }

    // ===== HTTP Client =====

    private function apiCall(string $method, string $endpoint, array $body=[], ?string $csid=null, ?string $secret=null, ?string $otp=null): array {
        $url=$this->getBaseUrl().$endpoint;
        $h=['Content-Type: application/json','Accept: application/json','Accept-Version: V2','Accept-Language: ar'];
        if ($csid&&$secret) $h[]='Authorization: Basic '.base64_encode($csid.':'.$secret);
        if ($otp) $h[]='OTP: '.$otp;

        $ch=curl_init();
        curl_setopt_array($ch,[
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $h,
            CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $resp=curl_exec($ch);
        $code=curl_getinfo($ch,CURLINFO_HTTP_CODE);
        $err=curl_error($ch);
        curl_close($ch);

        if ($err) return ['success'=>false,'error'=>'Connection: '.$err,'http_code'=>0,'body'=>null,'raw_response'=>''];

        $rb=json_decode($resp,true);
        $ok=in_array($code,[200,202]);

        return [
            'success'      => $ok,
            'http_code'    => $code,
            'body'         => $rb,
            'raw_response' => $resp,
            'error'        => $ok ? null : ($rb['validationResults']['errorMessages'][0]['message']??$rb['message']??'HTTP '.$code),
        ];
    }
}
