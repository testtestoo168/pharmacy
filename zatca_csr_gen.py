#!/usr/bin/env python3
"""
ZATCA Phase 2 - CSR Generator
Called by PHP via shell_exec, reads JSON from stdin, outputs JSON
"""
import sys
import json

try:
    from cryptography.hazmat.primitives.asymmetric import ec
    from cryptography.hazmat.primitives import hashes, serialization
    from cryptography import x509
    from cryptography.x509.oid import NameOID
except ImportError:
    print(json.dumps({"success": False, "error": "cryptography library not installed"}))
    sys.exit(1)

def generate_zatca_csr(params):
    cn       = params.get('common_name', 'EGS-URS-Pharmacy')
    sn       = params.get('serial_number', '1-URS|2-1.0|3-001')
    org_id   = params.get('org_identifier', '')
    ou       = params.get('org_unit_name', '')
    org      = params.get('org_name', '')
    inv_type = params.get('invoice_type', '0100')
    location = params.get('location', '')
    industry = params.get('industry', 'Pharmacy')
    env      = params.get('env', 'sandbox')

    # ZATCA-Code-Signing for all environments
    code_signing_val = "ZATCA-Code-Signing"

    # Generate secp256k1 private key
    private_key = ec.generate_private_key(ec.SECP256K1())

    # Build SAN DirectoryName - all ZATCA required fields
    san_attrs = [
        x509.NameAttribute(NameOID.SERIAL_NUMBER, sn),
        x509.NameAttribute(NameOID.USER_ID, org_id),
        x509.NameAttribute(NameOID.TITLE, inv_type),
        x509.NameAttribute(x509.ObjectIdentifier("2.5.4.26"), location),
        x509.NameAttribute(x509.ObjectIdentifier("2.5.4.15"), industry),
    ]

    # UTF8String encoding for the OID value
    oid_val_encoded = bytes([0x0c, len(code_signing_val.encode())]) + code_signing_val.encode('utf-8')

    csr = (
        x509.CertificateSigningRequestBuilder()
        .subject_name(x509.Name([
            x509.NameAttribute(NameOID.COUNTRY_NAME, "SA"),
            x509.NameAttribute(NameOID.ORGANIZATIONAL_UNIT_NAME, ou),
            x509.NameAttribute(NameOID.ORGANIZATION_NAME, org),
            x509.NameAttribute(NameOID.COMMON_NAME, cn),
        ]))
        .add_extension(
            x509.UnrecognizedExtension(
                x509.ObjectIdentifier("1.3.6.1.4.1.311.20.2"),
                oid_val_encoded
            ),
            critical=False,
        )
        .add_extension(
            x509.SubjectAlternativeName([
                x509.DirectoryName(x509.Name(san_attrs))
            ]),
            critical=False,
        )
        .sign(private_key, hashes.SHA256())
    )

    # Export private key PEM
    pk_pem = private_key.private_bytes(
        encoding=serialization.Encoding.PEM,
        format=serialization.PrivateFormat.TraditionalOpenSSL,
        encryption_algorithm=serialization.NoEncryption()
    ).decode('utf-8')

    # Export CSR as base64 without headers/newlines
    csr_pem = csr.public_bytes(serialization.Encoding.PEM).decode('utf-8')
    csr_b64 = (csr_pem
        .replace('-----BEGIN CERTIFICATE REQUEST-----\n', '')
        .replace('\n-----END CERTIFICATE REQUEST-----\n', '')
        .replace('\n-----END CERTIFICATE REQUEST-----', '')
        .replace('\n', ''))

    return {
        "success": True,
        "private_key": pk_pem,
        "csr_b64": csr_b64,
    }

if __name__ == '__main__':
    try:
        params = json.loads(sys.stdin.read())
        result = generate_zatca_csr(params)
        print(json.dumps(result))
    except Exception as e:
        print(json.dumps({"success": False, "error": str(e)}))
