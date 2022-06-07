# Security Policy

## Reporting a vulnerability

If there are any vulnerabilities in **grommunio Sync**, don't hesitate to _report them_.

Please follow these steps for a morally sound and responsible vulnerability disclosure:

1. Use any of the [private contact addresses](https://github.com/grommunio/grommunio-sync#support).

2. Describe the vulnerability.

   If you have a fix, that is most welcome -- please attach or summarize it in your message!

3. We will evaluate the vulnerability and, if necessary, release a fix or mitigating steps to address it. We will contact you to let you know the outcome, and will credit you in the report.

   Please **do not disclose the vulnerability publicly** until a fix is released!

4. Once we have either a) published a fix, or b) declined to address the vulnerability for whatever reason, you are free to publicly disclose it.

## Vulnerability disclosure policy

- Prior to submitting your report, find out which cases are outside the scope of this policy and will not be processed under this framework.
- Email your findings on the security issue to any of the [private contact addresses](https://github.com/grommunio/grommunio-sync#support).
- Do not exploit the vulnerability or problem by, for example, downloading, modifying, deleting data, or uploading code.
- Do not disclose information about the vulnerability to third parties or institutions unless this has been cleared by grommunio.
- Do not conduct attacks on any systems that compromise, alter, or manipulate infrastructure not owned by yourself.
- Do not conduct social engineering (e.g., phishing), (distributed) denial of service, spam, or other attacks.
- Provide sufficient information for us to reproduce and analyze the problem. Also provide a means of contact for queries.
- Usually, the address or url of the affected system (if applicable) and a description of the vulnerability is sufficient. However, complex vulnerabilities may require further explanation and documentation.

### What we promise

- We will try to close the vulnerability as soon as possible.
- You will receive feedback from us on your report.
- If you act in accordance with the above instructions, law enforcement authorities will not be informed in connection with your findings. This does not apply if there are identifiable criminal or intelligence intentions.
- We will treat your report confidentially and will not disclose your personal data to third parties without your consent.
- We will inform you about the receipt of your report, and also about the validity of the vulnerability and the resolution of the problem during the period of processing.
- The finder is judged by his or her abilities and not by age, education, gender and origin or social rank. We also show this respect publicly and recognize this achievement. To this end, unless otherwise requested, we will include a thank you statement for closed vulnerability and the name (or alias) of the discoverer, thus also publicly expressing good cooperation with grommunio.

### Qualified reporting of vulnerabilities

Any design or implementation issue can be reported that is reproducible and affects security.

Common examples include:

- Cross Site Request Forgery (CSRF)
- Cross Site Scripting (XSS)
- Insecure Direct Object Reference
- Remote Code Execution (RCE) - Injection Flaws
- Information Leakage and Improper Error Handling
- Unauthorized access to properties or accounts and many more.

These can also be:

- Data / Information Leaks
- Possibility of data / information exfiltration
- Actively exploitable backdoors (backdoors)
- Possibility of unauthorized system use
- Misconfigurations
- Non-qualified vulnerabilities

The following vulnerabilities do not fall within the scope of the vulnerability disclosure policy:

- Attacks that require physical access to a user's device or network.
- Forms with missing CSRF tokens (exception: criticality exceeds Common Vulnerability Scoring System (CVSS) level 5).
- Missing security headers that do not directly lead to an exploitable vulnerability.
- Using a library known to be vulnerable or publicly known to be broken (without active evidence of exploitability).
- Reports from automated tools or scans without explanatory documentation.
- Bots, SPAM, mass registration.
- Failure to submit best practices (e.g., certificate pinning, security header).
- Use of vulnerable and "weak" cipher suites/ciphers.

### Format template of a vulnerability report

- Title / name of the vulnerability
- Vulnerability type
- Short explanation of the vulnerability (without technical details)
- Affected product / service / device
- Exploitation technique
  - Remote
  - Local
  - Network
  - Physical
- Authentication type
  - Pre-Auth
  - Authentication Guest
  - User privileges (User / Admin)
- User Interaction
  - No User
  - Low User Interaction
  - Medium User Interaction
  - High User Interaction
- Technical details and description of the vulnerability
- Proof of Concept
- Demonstration of a possible solution
- Author and contact details
- Consent to mention the name and the found vulnerability in the acknowledgements
