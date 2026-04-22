# Security Policy

This document describes how we handle security issues for **Dual Check 2FA** (WordPress plugin).

## Reporting a Vulnerability

**Please do not open a public GitHub issue** for undisclosed security problems.

### Preferred: GitHub private reporting

1. Open the repository on GitHub.
2. Go to **Security** → **Report a vulnerability** (or **Advisories** → **Report a vulnerability**).
3. Submit a detailed report (steps to reproduce, affected versions, impact, and any suggested fix if you have one).

### Alternative: email

If private advisories are not available, contact the maintainer **directly by email** with subject line `[SECURITY] Dual Check 2FA` and the same detail you would put in an advisory. 

Contact email: ash75049@gmail.com

## What to expect after you report

- **Acknowledgement:** I will try my best to get back to you but I am busy.
- **Updates:** We will keep you informed of material progress when a fix is being prepared and when it is released.
- **Disclosure:** We prefer coordinated disclosure. Please allow a reasonable window for a patch before public details. We credit reporters when they wish to be named.
- **Declined reports:** If we determine something is not a vulnerability in this product, out of scope, or not reproducible, we will explain briefly.

## Scope and out-of-scope examples

**In scope**

- Authentication / authorization flaws in the plugin (login second step, admin settings save paths, capability checks).
- Issues that could lead to privilege escalation, arbitrary code execution, or sensitive data exposure **through the plugin’s code or stored configuration**.
- Cryptographic or session-handling weaknesses in the plugin’s login flow.

**Typically out of scope**

- WordPress core bugs (report to the WordPress project).
- Server or host misconfiguration (e.g. exposed upload directories, PHP version past EOL).
- Social engineering or stolen administrator credentials.

Thank you for helping keep Dual Check 2FA users safe.
