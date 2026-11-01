# Code inventory — 100% traceability

**Baseline spec**: [`spec.md`](spec.md)  
**Package**: `nowo-tech/mail-kit-bundle`  
**Last audited**: 2026-11-01

Scope: every file under `src/`. Audit command: `find src -type f | wc -l` → **70**.

## Bundle and dependency injection

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/NowoMailKitBundle.php` | Bundle and dependency injection | FR-BUNDLE-001 |
| `src/DependencyInjection/Configuration.php` | Bundle and dependency injection | FR-CFG-001 |
| `src/DependencyInjection/NowoMailKitExtension.php` | Bundle and dependency injection | FR-CFG-002 |
| `src/DependencyInjection/Compiler/MailerIntegrationPass.php` | Bundle and dependency injection | FR-CFG-003 |
| `src/DependencyInjection/Compiler/CsrfProtectionPass.php` | Bundle and dependency injection | FR-CFG-004 |
| `src/DependencyInjection/Compiler/UnauthenticatedAccessPass.php` | Bundle and dependency injection | FR-CFG-004 |
| `src/DependencyInjection/Compiler/TwigPathsPass.php` | Bundle and dependency injection | FR-TWIG-001 |

## Settings

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/Settings/MailerSettingsSection.php` | Settings | FR-SET-001 |
| `src/Settings/ResendSettingsSection.php` | Settings | FR-SET-002 |
| `src/Settings/MailSettings.php` | Settings | FR-SET-003 |

## DSN and sender

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/Dsn/MailerDsnProviderInterface.php` | DSN and sender | FR-DSN-001 |
| `src/Dsn/SettingsMailerDsnProvider.php` | DSN and sender | FR-DSN-001, FR-SET-003 |
| `src/Dsn/RuntimeDsn.php` | DSN and sender | FR-DSN-001 |
| `src/Dsn/DsnSource.php` | DSN and sender | FR-DSN-001 |
| `src/Dsn/MailerDsnValidator.php` | DSN and sender | FR-DSN-002 |
| `src/Dsn/DsnHostGuard.php` | DSN and sender | FR-DSN-003 |
| `src/Dsn/MailerDsnAudit.php` | DSN and sender | FR-DSN-004 |
| `src/Validator/MailerDsn.php` | DSN and sender | FR-DSN-005 |
| `src/Validator/MailerDsnConstraintValidator.php` | DSN and sender | FR-DSN-005 |
| `src/Validator/DeliverableSender.php` | DSN and sender | FR-DSN-005, FR-SND-001 |
| `src/Validator/DeliverableSenderValidator.php` | DSN and sender | FR-DSN-005, FR-SND-001 |
| `src/Sender/SenderAddress.php` | DSN and sender | FR-SND-001 |
| `src/Sender/SenderPolicy.php` | DSN and sender | FR-SND-001 |

## Delivery

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/Mailer/RuntimeTransport.php` | Delivery | FR-MAIL-001 |
| `src/Mailer/MailKitMailer.php` | Delivery | FR-MAIL-002 |
| `src/Mailer/MailHeaders.php` | Delivery | FR-MAIL-003 |
| `src/Mailer/DeliveryFailedException.php` | Delivery | FR-MAIL-004 |

## Test e-mail and Resend

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/TestMail/TestMailSender.php` | Test e-mail and Resend | FR-TEST-001 |
| `src/TestMail/TestMailGuard.php` | Test e-mail and Resend | FR-TEST-002 |
| `src/TestMail/TestMailOutcome.php` | Test e-mail and Resend | FR-TEST-002 |
| `src/TestMail/TestMailResult.php` | Test e-mail and Resend | FR-TEST-002 |
| `src/Command/SendTestMailCommand.php` | Test e-mail and Resend | FR-TEST-002 |
| `src/Resend/ResendApiChecker.php` | Test e-mail and Resend | FR-RES-001 |
| `src/Resend/ResendCheckResult.php` | Test e-mail and Resend | FR-RES-001 |

## Sent-mail log

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/Entity/SentMail.php` | Sent-mail log | FR-LOG-001 |
| `src/Entity/SentMailBody.php` | Sent-mail log | FR-LOG-001 |
| `src/Log/SentMailRecorder.php` | Sent-mail log | FR-LOG-002 |
| `src/Log/SentMailRepository.php` | Sent-mail log | FR-LOG-003 |
| `src/Log/SentMailFilters.php` | Sent-mail log | FR-LOG-003 |
| `src/Log/ErrorScrubber.php` | Sent-mail log | FR-LOG-004 |
| `src/Log/SentMailStatus.php` | Sent-mail log | FR-LOG-004 |
| `src/Log/SentMailCsvExporter.php` | Sent-mail log | FR-LOG-006 |
| `src/Command/PurgeSentMailLogCommand.php` | Sent-mail log | FR-LOG-005 |
| `src/Command/ForgetRecipientCommand.php` | Sent-mail log | FR-LOG-005 |
| `src/Doctrine/TablePrefixListener.php` | Sent-mail log | FR-ORM-001 |

## Readiness

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/Readiness/OutboundMailReadiness.php` | Readiness | FR-RDY-001 |
| `src/Readiness/MailStatus.php` | Readiness | FR-RDY-001 |
| `src/Twig/MailKitTwigExtension.php` | Readiness | FR-RDY-001 |
| `src/Integration/AuthKit/MailKitOutboundMailReadyChecker.php` | Readiness | FR-RDY-001 |
| `src/EventListener/SettingsChangedListener.php` | Readiness | FR-RDY-002 |

## Admin UI

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/Routing/MailKitRouteLoader.php` | Admin UI | FR-UI-001 |
| `src/Controller/MailKitController.php` | Admin UI | FR-UI-002 |
| `src/Ui/AdminPages.php` | Admin UI | FR-UI-003 |
| `src/Ui/MailKitPages.php` | Admin UI | FR-UI-003 |
| `src/Ui/SettingsActions.php` | Admin UI | FR-UI-003, FR-SET-001, FR-SET-002 |
| `src/Form/SentMailFilterType.php` | Admin UI | FR-UI-003 |
| `src/Resources/views/log/index.html.twig` | Admin UI | FR-UI-003 |
| `src/Resources/views/log/show.html.twig` | Admin UI | FR-UI-003 |
| `src/Resources/translations/NowoMailKitBundle.de.yaml` | Admin UI | FR-UI-003 |
| `src/Resources/translations/NowoMailKitBundle.en.yaml` | Admin UI | FR-UI-003 |
| `src/Resources/translations/NowoMailKitBundle.es.yaml` | Admin UI | FR-UI-003 |
| `src/Resources/translations/NowoMailKitBundle.fr.yaml` | Admin UI | FR-UI-003 |
| `src/Resources/translations/NowoMailKitBundle.it.yaml` | Admin UI | FR-UI-003 |
| `src/Resources/translations/NowoMailKitBundle.nl.yaml` | Admin UI | FR-UI-003 |
| `src/Resources/translations/NowoMailKitBundle.pt.yaml` | Admin UI | FR-UI-003 |
| `src/Security/MailKitAccessCheckerInterface.php` | Admin UI | FR-UI-004 |
| `src/Security/ConfigurableMailKitAccessChecker.php` | Admin UI | FR-UI-004 |
| `src/Security/AllowAllMailKitAccessChecker.php` | Admin UI | FR-UI-004 |
| `src/Security/AccessGuard.php` | Admin UI | FR-UI-004 |
| `src/EventListener/MailKitAccessListener.php` | Admin UI | FR-UI-004 |
