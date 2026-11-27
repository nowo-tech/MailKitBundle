# Code inventory — 100% traceability

**Baseline spec**: [`spec.md`](spec.md)  
**Package**: `nowo-tech/mail-kit-bundle`  
**Last audited**: 2026-11-27 (2.7.0)

Scope: every file under `src/`. Audit command: `find src -type f | wc -l` → **324** (2.7.0, stored e-mail templates; 2.6.0 had 289; re-checked against `git ls-files src`: no unmapped file, no stale row).

## Bundle and dependency injection

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/NowoMailKitBundle.php` | Bundle and dependency injection | FR-BUNDLE-001 |
| `src/DependencyInjection/Configuration.php` | Bundle and dependency injection | FR-CFG-001, FR-DBO-001 ([002](../002-db-only-seed/spec.md)) |
| `src/DependencyInjection/NowoMailKitExtension.php` | Bundle and dependency injection | FR-CFG-002 |
| `src/DependencyInjection/Compiler/MailerIntegrationPass.php` | Bundle and dependency injection | FR-CFG-003 |
| `src/DependencyInjection/Compiler/SenderPortPass.php` | Bundle and dependency injection | FR-CFG-003, FR-SND-001 |
| `src/DependencyInjection/Compiler/CsrfProtectionPass.php` | Bundle and dependency injection | FR-CFG-004 |
| `src/DependencyInjection/Compiler/UnauthenticatedAccessPass.php` | Bundle and dependency injection | FR-CFG-004 |
| `src/DependencyInjection/Compiler/TwigPathsPass.php` | Bundle and dependency injection | FR-TWIG-001 |
| `src/DependencyInjection/Compiler/MailboxUrlGuardPass.php` | Bundle and dependency injection | FR-CFG-003, FR-MBX-004 |
| `src/DependencyInjection/Compiler/MailboxSendTransactionPass.php` | Bundle and dependency injection | FR-CFG-003, FR-MBX-009 |
| `src/DependencyInjection/Compiler/MailboxHttpRetryPass.php` | Bundle and dependency injection | FR-CFG-003, FR-MBX-009 |
| `src/DependencyInjection/Compiler/TemplatePlaceholderPass.php` | Bundle and dependency injection | FR-TPL-007 ([006](../006-stored-templates/spec.md)) |

## Settings

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/Settings/MailerSettingsSection.php` | Settings | FR-SET-001, FR-DBO-002 ([002](../002-db-only-seed/spec.md)), FR-PRV-001, FR-PRV-004 ([003](../003-provider-fields/spec.md)) |
| `src/Settings/LegacyResendSettings.php` | Settings | FR-PRV-004 ([003](../003-provider-fields/spec.md)) |
| `src/Form/LegacyResendSettingsTypeExtension.php` | Settings | FR-PRV-004 ([003](../003-provider-fields/spec.md)) |
| `src/Command/PurgeLegacyResendCommand.php` | Settings | FR-PRV-007 ([003](../003-provider-fields/spec.md)) |
| `src/Settings/MailSettings.php` | Settings | FR-SET-003, FR-DBO-003 ([002](../002-db-only-seed/spec.md)), FR-PRV-002, FR-PRV-004 ([003](../003-provider-fields/spec.md)) |
| `src/EventListener/LegacyResendSettingsResetListener.php` | Settings | FR-PRV-004 ([003](../003-provider-fields/spec.md)), FR-WRK-001 |

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
| `src/Validator/ResendApiKeyAccepted.php` | DSN and sender | FR-PRV-003 ([003](../003-provider-fields/spec.md)) |
| `src/Validator/ResendApiKeyAcceptedValidator.php` | DSN and sender | FR-PRV-003 ([003](../003-provider-fields/spec.md)) |
| `src/Sender/SenderAddress.php` | DSN and sender | FR-SND-001 |
| `src/Sender/SenderPolicy.php` | DSN and sender | FR-SND-001 |
| `src/Sender/SenderIdentityProviderInterface.php` | DSN and sender | FR-SND-001 |
| `src/Sender/MailAddress.php` | DSN and sender | FR-SND-001 |
| `src/Sender/MimeAddressMapper.php` | DSN and sender | FR-SND-001 |

## Delivery

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/Mailer/RuntimeTransport.php` | Delivery | FR-MAIL-001 |
| `src/Mailer/MailKitMailer.php` | Delivery | FR-MAIL-002 |
| `src/Mailer/MailHeaders.php` | Delivery | FR-MAIL-003 |
| `src/Mailer/DeliveryFailedException.php` | Delivery | FR-MAIL-004 |
| `src/Mailer/UnrecoverableDeliveryFailedException.php` | Delivery | FR-MAIL-004 |

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
| `src/Doctrine/ExactCollation.php` | Sent-mail log | FR-SEC-001 |
| `src/Log/SentMailLogTableMissingException.php` | Sent-mail log | FR-LOG-002 |
| `src/Doctrine/MailKitExactKeyColumns.php` | Sent-mail log | FR-SEC-001 |

## Readiness

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/Readiness/OutboundMailReadiness.php` | Readiness | FR-RDY-001, FR-DBO-003 ([002](../002-db-only-seed/spec.md)) |
| `src/Readiness/MailStatus.php` | Readiness | FR-RDY-001, FR-DBO-003 ([002](../002-db-only-seed/spec.md)) |
| `src/Twig/MailKitTwigExtension.php` | Readiness | FR-RDY-001 |
| `src/Integration/AuthKit/MailKitOutboundMailReadyChecker.php` | Readiness | FR-RDY-001 |
| `src/EventListener/SettingsChangedListener.php` | Readiness | FR-RDY-002 |
| `src/Readiness/OutboundMailReadinessInterface.php` | Readiness | FR-RDY-001 |

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

## Events

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/Event/MailDeliveredEvent.php` | Events | FR-EVT-001 |
| `src/Event/MailDeliveryFailedEvent.php` | Events | FR-EVT-001 |
| `src/Event/MailMachineOpenedEvent.php` | Events | FR-TRK-004 |
| `src/Event/MailOpenedEvent.php` | Events | FR-TRK-004 |
| `src/Event/SentMailPersonalDataErasedEvent.php` | Events | FR-GDPR-002 |

## Personal data (GDPR)

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/Integration/UserKit/EmailTrackingPersonalDataEraser.php` | Personal data (GDPR) | FR-TRK-007 |
| `src/Integration/UserKit/EmailTrackingPersonalDataExportProvider.php` | Personal data (GDPR) | FR-TRK-007 |
| `src/Integration/UserKit/EmailsReceivedPersonalDataEraser.php` | Personal data (GDPR) | FR-GDPR-001, FR-GDPR-002 |
| `src/Integration/UserKit/EmailsReceivedPersonalDataExportProvider.php` | Personal data (GDPR) | FR-GDPR-003 |
| `src/Integration/UserKit/EmailTemplatesPersonalDataEraser.php` | Personal data (GDPR) | FR-TPL-011 ([006](../006-stored-templates/spec.md)) |
| `src/Integration/UserKit/EmailTemplatesPersonalDataExportProvider.php` | Personal data (GDPR) | FR-TPL-011 ([006](../006-stored-templates/spec.md)) |
| `src/Log/DefaultSentMailPersonalDataSubjectResolver.php` | Personal data (GDPR) | FR-GDPR-001, FR-GDPR-002 |
| `src/Log/NullSentMailErasure.php` | Personal data (GDPR) | FR-GDPR-001 |
| `src/Log/NullSentMailPersonalDataExporter.php` | Personal data (GDPR) | FR-GDPR-003 |
| `src/Log/SentMailErasureInterface.php` | Personal data (GDPR) | FR-GDPR-001 |
| `src/Log/SentMailErasureResult.php` | Personal data (GDPR) | FR-GDPR-001 |
| `src/Log/SentMailPersonalDataEraserInterface.php` | Personal data (GDPR) | FR-GDPR-001, FR-GDPR-002 |
| `src/Log/SentMailPersonalDataExporterInterface.php` | Personal data (GDPR) | FR-GDPR-003 |
| `src/Log/SentMailPersonalDataSubject.php` | Personal data (GDPR) | FR-GDPR-001, FR-GDPR-002 |
| `src/Log/SentMailPersonalDataSubjectResolverInterface.php` | Personal data (GDPR) | FR-GDPR-001, FR-GDPR-002 |

## Branded e-mail

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/Brand/BrandedEmailHelper.php` | Branded e-mail | FR-BRAND-001, FR-002 ([004](../004-branded-email/spec.md)) |
| `src/Brand/BrandedEmailImage.php` | Branded e-mail | FR-BRAND-001, FR-004 ([004](../004-branded-email/spec.md)) |
| `src/Brand/BrandedEmailPart.php` | Branded e-mail | FR-BRAND-001, FR-004 ([004](../004-branded-email/spec.md)) |
| `src/Brand/BrandedEmailText.php` | Branded e-mail | FR-BRAND-001, FR-004 ([004](../004-branded-email/spec.md)) |
| `src/Resources/views/email/branded_layout.html.twig` | Branded e-mail | FR-BRAND-001, FR-001 ([004](../004-branded-email/spec.md)) |
| `src/Resources/views/email/branded_layout.txt.twig` | Branded e-mail | FR-BRAND-001, FR-001 ([004](../004-branded-email/spec.md)) |
| `src/Twig/MailKitBrandTwigExtension.php` | Branded e-mail | FR-BRAND-002, FR-005 ([004](../004-branded-email/spec.md)) |

## Microsoft Graph sending

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/Graph/ConfiguredGraphMailboxProvider.php` | Microsoft Graph sending | FR-GRS-001 |
| `src/Graph/GraphApiClient.php` | Microsoft Graph sending | FR-GRS-001 |
| `src/Graph/GraphException.php` | Microsoft Graph sending | FR-GRS-001 |
| `src/Graph/GraphMailbox.php` | Microsoft Graph sending | FR-GRS-001 |
| `src/Graph/GraphMailboxProviderInterface.php` | Microsoft Graph sending | FR-GRS-001 |
| `src/Graph/GraphSecret.php` | Microsoft Graph sending | FR-GRS-001 |
| `src/Graph/GraphTokenProvider.php` | Microsoft Graph sending | FR-GRS-001 |
| `src/Graph/Send/AttachmentFilename.php` | Microsoft Graph sending | FR-GRS-002 |
| `src/Graph/Send/DeleteGraphDraft.php` | Microsoft Graph sending | FR-GRS-003 |
| `src/Graph/Send/DeleteGraphDraftHandler.php` | Microsoft Graph sending | FR-GRS-003 |
| `src/Graph/Send/GraphAttachment.php` | Microsoft Graph sending | FR-GRS-002 |
| `src/Graph/Send/GraphMailSender.php` | Microsoft Graph sending | FR-GRS-002 |
| `src/Graph/Send/GraphOutgoingMail.php` | Microsoft Graph sending | FR-GRS-002 |
| `src/Graph/Send/GraphRecipient.php` | Microsoft Graph sending | FR-GRS-002 |
| `src/Graph/Send/GraphSendLimits.php` | Microsoft Graph sending | FR-GRS-002 |
| `src/Graph/Send/GraphSendResult.php` | Microsoft Graph sending | FR-GRS-002 |
| `src/Graph/Send/PlusAddress.php` | Microsoft Graph sending | FR-GRS-002 |
| `src/Graph/Transport/GraphTransport.php` | Microsoft Graph sending | FR-GRS-004 |
| `src/Graph/Transport/GraphTransportFactory.php` | Microsoft Graph sending | FR-GRS-004 |
| `src/Graph/Transport/GraphUnrecoverableTransportException.php` | Microsoft Graph sending | FR-GRS-004 |
| `src/Graph/GraphCredentialInterface.php` | Microsoft Graph sending | FR-GRS-001, FR-MBX-004 |
| `src/Graph/AppOnlyGraphCredential.php` | Microsoft Graph sending | FR-GRS-001 |
| `src/Graph/GraphRequestOptionsInterface.php` | Microsoft Graph sending | FR-GRS-001, FR-MBX-004 |
| `src/Graph/GuardedGraphRequestOptions.php` | Microsoft Graph sending | FR-MBX-004 |

## Microsoft Graph inbound

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/Graph/Inbound/AuthenticationResults.php` | Microsoft Graph inbound | FR-GRI-003 |
| `src/Graph/Inbound/Command/GraphSchemaCommand.php` | Microsoft Graph inbound | FR-GRI-005 |
| `src/Graph/Inbound/Command/GraphSubscriptionsCommand.php` | Microsoft Graph inbound | FR-GRI-005 |
| `src/Graph/Inbound/Command/GraphSweepCommand.php` | Microsoft Graph inbound | FR-GRI-005 |
| `src/Graph/Inbound/ConfiguredInboundProfiles.php` | Microsoft Graph inbound | FR-GRI-004 |
| `src/Graph/Inbound/GraphDeltaSweeper.php` | Microsoft Graph inbound | FR-GRI-002 |
| `src/Graph/Inbound/GraphInboundHandlerInterface.php` | Microsoft Graph inbound | FR-GRI-004 |
| `src/Graph/Inbound/GraphInboundMessage.php` | Microsoft Graph inbound | FR-GRI-003 |
| `src/Graph/Inbound/GraphInboundProcessor.php` | Microsoft Graph inbound | FR-GRI-004 |
| `src/Graph/Inbound/GraphInboundProfilesProviderInterface.php` | Microsoft Graph inbound | FR-GRI-004 |
| `src/Graph/Inbound/GraphMessageNormalizer.php` | Microsoft Graph inbound | FR-GRI-003 |
| `src/Graph/Inbound/GraphSubscriptionManager.php` | Microsoft Graph inbound | FR-GRI-002 |
| `src/Graph/Inbound/InboundAction.php` | Microsoft Graph inbound | FR-GRI-004 |
| `src/Graph/Inbound/InboundAddress.php` | Microsoft Graph inbound | FR-GRI-003 |
| `src/Graph/Inbound/InboundAttachment.php` | Microsoft Graph inbound | FR-GRI-003 |
| `src/Graph/Inbound/InboundDecision.php` | Microsoft Graph inbound | FR-GRI-004 |
| `src/Graph/Inbound/InboundFileTypes.php` | Microsoft Graph inbound | FR-GRI-003 |
| `src/Graph/Inbound/InboundHtmlSanitizer.php` | Microsoft Graph inbound | FR-GRI-003 |
| `src/Graph/Inbound/InboundLimits.php` | Microsoft Graph inbound | FR-GRI-003 |
| `src/Graph/Inbound/Message/FetchGraphMessage.php` | Microsoft Graph inbound | FR-GRI-004 |
| `src/Graph/Inbound/Message/GraphInboundMessageHandler.php` | Microsoft Graph inbound | FR-GRI-004 |
| `src/Graph/Inbound/Message/RenewGraphSubscriptions.php` | Microsoft Graph inbound | FR-GRI-004 |
| `src/Graph/Inbound/Message/SweepGraphMailbox.php` | Microsoft Graph inbound | FR-GRI-004 |
| `src/Graph/Inbound/Store/GraphInboundSchemaListener.php` | Microsoft Graph inbound | FR-GRI-006 |
| `src/Graph/Inbound/Store/GraphInboundStore.php` | Microsoft Graph inbound | FR-GRI-006, FR-SEC-001 |
| `src/Graph/Inbound/Webhook/GraphWebhookController.php` | Microsoft Graph inbound | FR-GRI-001 |
| `src/Graph/Inbound/Webhook/GraphWebhookRouteLoader.php` | Microsoft Graph inbound | FR-GRI-001 |
| `src/Graph/Inbound/Webhook/AbstractGraphWebhookController.php` | Microsoft Graph inbound | FR-GRI-001, FR-MBX-006 |

## Open and click tracking

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/Tracking/Command/TrackingPurgeCommand.php` | Open and click tracking | FR-TRK-006 |
| `src/Tracking/Command/TrackingSchemaCommand.php` | Open and click tracking | FR-TRK-006 |
| `src/Tracking/ConfiguredTrackableLinkFilter.php` | Open and click tracking | FR-TRK-002 |
| `src/Tracking/CredentialLinkDetector.php` | Open and click tracking | FR-TRK-002 |
| `src/Tracking/DefaultTrackingRecipientResolver.php` | Open and click tracking | FR-TRK-001 |
| `src/Tracking/EmailLinkRewriterInterface.php` | Open and click tracking | FR-TRK-002 |
| `src/Tracking/EmailTracker.php` | Open and click tracking | FR-TRK-001 |
| `src/Tracking/EmailTrackingPersonalDataInterface.php` | Open and click tracking | FR-TRK-007 |
| `src/Tracking/EmailTrackingPolicyInterface.php` | Open and click tracking | FR-TRK-001 |
| `src/Tracking/EmailTrackingReaderInterface.php` | Open and click tracking | FR-TRK-005 |
| `src/Tracking/HtmlLinkTracker.php` | Open and click tracking | FR-TRK-002 |
| `src/Tracking/Http/TrackingPixelController.php` | Open and click tracking | FR-TRK-003 |
| `src/Tracking/Http/TrackingRouteLoader.php` | Open and click tracking | FR-TRK-003 |
| `src/Tracking/Message/RecordMailOpen.php` | Open and click tracking | FR-TRK-003 |
| `src/Tracking/Message/RecordMailOpenHandler.php` | Open and click tracking | FR-TRK-003 |
| `src/Tracking/NullEmailLinkRewriter.php` | Open and click tracking | FR-TRK-002 |
| `src/Tracking/OpenClassifier.php` | Open and click tracking | FR-TRK-003 |
| `src/Tracking/OpenRecorder.php` | Open and click tracking | FR-TRK-003 |
| `src/Tracking/OpenStats.php` | Open and click tracking | FR-TRK-005 |
| `src/Tracking/Store/TrackingSchemaListener.php` | Open and click tracking | FR-TRK-006 |
| `src/Tracking/Store/TrackingStore.php` | Open and click tracking | FR-TRK-006, FR-SEC-001 |
| `src/Tracking/TrackableLinkFilterInterface.php` | Open and click tracking | FR-TRK-002 |
| `src/Tracking/TrackedEmail.php` | Open and click tracking | FR-TRK-001 |
| `src/Tracking/TrackedLink.php` | Open and click tracking | FR-TRK-002 |
| `src/Tracking/TrackingBaseUrl.php` | Open and click tracking | FR-TRK-001 |
| `src/Tracking/TrackingCandidate.php` | Open and click tracking | FR-TRK-001 |
| `src/Tracking/TrackingDecision.php` | Open and click tracking | FR-TRK-001 |
| `src/Tracking/TrackingIds.php` | Open and click tracking | FR-TRK-001 |
| `src/Tracking/TrackingRecipientResolverInterface.php` | Open and click tracking | FR-TRK-001 |
| `src/Tracking/TrackingTokenSigner.php` | Open and click tracking | FR-TRK-003 |

## Concurrency

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/Concurrency/StorageConflicts.php` | Storage-conflict classifier on top of transaction-doctrine's (deadlock, lock wait, serialization, transient runner failure, lost savepoint; never unique violations or lost connections) | FR-CONC-001 |
| `src/EventListener/MailKitConflictListener.php` | Conflict answer (409 / 503 + `X-Conflict-Code` `CONFLICT_001` / `CONFLICT_002`) on the bundle's own routes | FR-CONC-001 |
| `src/Concurrency/SavepointWrites.php` | Single-statement writes in savepoints inside a host transaction (Graph inbound store, mailbox store) | FR-CONC-001, FR-MBX-008 |

## Delegated mailboxes: host ports and services

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/Mailbox/Port/KeepAllExclusionPolicy.php` | Host ports and services (2.5.0) | FR-MBX-001; FR-001, FR-002, FR-005, FR-014 ([005](../005-delegated-mailboxes/spec.md)) |
| `src/Mailbox/Port/MailboxCallObserverInterface.php` | Host ports and services (2.5.0) | FR-MBX-001; FR-001, FR-002, FR-005, FR-014 ([005](../005-delegated-mailboxes/spec.md)) |
| `src/Mailbox/Port/MailboxDirectoryInterface.php` | Host ports and services (2.5.0) | FR-MBX-001; FR-001, FR-002, FR-005, FR-014 ([005](../005-delegated-mailboxes/spec.md)) |
| `src/Mailbox/Port/MailboxExclusionPolicyInterface.php` | Host ports and services (2.5.0) | FR-MBX-001; FR-001, FR-002, FR-005, FR-014 ([005](../005-delegated-mailboxes/spec.md)) |
| `src/Mailbox/Port/MailboxMessageIndexInterface.php` | Host ports and services (2.5.0) | FR-MBX-001; FR-001, FR-002, FR-005, FR-014 ([005](../005-delegated-mailboxes/spec.md)) |
| `src/Mailbox/Port/MailboxMessageSinkInterface.php` | Host ports and services (2.5.0) | FR-MBX-001; FR-001, FR-002, FR-005, FR-014 ([005](../005-delegated-mailboxes/spec.md)) |
| `src/Mailbox/Port/MailboxTokenProviderInterface.php` | Host ports and services (2.5.0) | FR-MBX-001; FR-001, FR-002, FR-005, FR-014 ([005](../005-delegated-mailboxes/spec.md)) |
| `src/Mailbox/Port/NullMailboxCallObserver.php` | Host ports and services (2.5.0) | FR-MBX-001; FR-001, FR-002, FR-005, FR-014 ([005](../005-delegated-mailboxes/spec.md)) |
| `src/Mailbox/Service/MailboxAttachmentReaderInterface.php` | Host ports and services (2.5.0) | FR-MBX-001; FR-001, FR-002, FR-005, FR-014 ([005](../005-delegated-mailboxes/spec.md)) |
| `src/Mailbox/Service/MailboxSyncInterface.php` | Host ports and services (2.5.0) | FR-MBX-001; FR-001, FR-002, FR-005, FR-014 ([005](../005-delegated-mailboxes/spec.md)) |

## Delegated mailboxes: sync engine

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/Mailbox/Engine/ChangeItem.php` | Sync engine (2.5.0) | FR-MBX-002; FR-004, FR-007, FR-012, FR-016 ([005](../005-delegated-mailboxes/spec.md)) |
| `src/Mailbox/Engine/ChangePage.php` | Sync engine (2.5.0) | FR-MBX-002; FR-004, FR-007, FR-012, FR-016 ([005](../005-delegated-mailboxes/spec.md)) |
| `src/Mailbox/Engine/CursorExpired.php` | Sync engine (2.5.0) | FR-MBX-002; FR-004, FR-007, FR-012, FR-016 ([005](../005-delegated-mailboxes/spec.md)) |
| `src/Mailbox/Engine/FetchedMessage.php` | Sync engine (2.5.0) | FR-MBX-002; FR-004, FR-007, FR-012, FR-016 ([005](../005-delegated-mailboxes/spec.md)) |
| `src/Mailbox/Engine/MailboxAttachmentReader.php` | Sync engine (2.5.0) | FR-MBX-002; FR-004, FR-007, FR-012, FR-016 ([005](../005-delegated-mailboxes/spec.md)) |
| `src/Mailbox/Engine/MailboxGatewayInterface.php` | Sync engine (2.5.0) | FR-MBX-002; FR-004, FR-007, FR-012, FR-016 ([005](../005-delegated-mailboxes/spec.md)) |
| `src/Mailbox/Engine/MailboxSession.php` | Sync engine (2.5.0) | FR-MBX-002; FR-004, FR-007, FR-012, FR-016 ([005](../005-delegated-mailboxes/spec.md)) |
| `src/Mailbox/Engine/MailboxSyncEngine.php` | Sync engine (2.5.0) | FR-MBX-002; FR-004, FR-007, FR-012, FR-016 ([005](../005-delegated-mailboxes/spec.md)) |
| `src/Mailbox/Engine/MailboxSyncOptions.php` | Sync engine (2.5.0) | FR-MBX-002; FR-004, FR-007, FR-012, FR-016 ([005](../005-delegated-mailboxes/spec.md)) |
| `src/Mailbox/Engine/ProviderAccount.php` | Sync engine (2.5.0) | FR-MBX-002; FR-004, FR-007, FR-012, FR-016 ([005](../005-delegated-mailboxes/spec.md)) |
| `src/Mailbox/Engine/RemoteSubscription.php` | Sync engine (2.5.0) | FR-MBX-002; FR-004, FR-007, FR-012, FR-016 ([005](../005-delegated-mailboxes/spec.md)) |

## Delegated mailboxes: send

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/Mailbox/Engine/DraftRef.php` | Send (2.5.0) | FR-MBX-009; FR-008, FR-012, FR-015 ([005](../005-delegated-mailboxes/spec.md)) |
| `src/Mailbox/Engine/DraftState.php` | Send (2.5.0) | FR-MBX-009; FR-008, FR-012, FR-015 ([005](../005-delegated-mailboxes/spec.md)) |
| `src/Mailbox/Engine/MailboxSendOptions.php` | Send (2.5.0) | FR-MBX-009; FR-008, FR-012, FR-015 ([005](../005-delegated-mailboxes/spec.md)) |
| `src/Mailbox/Engine/MailboxSender.php` | Send (2.5.0) | FR-MBX-009; FR-008, FR-012, FR-015 ([005](../005-delegated-mailboxes/spec.md)) |
| `src/Mailbox/Engine/SendState.php` | Send (2.5.0) | FR-MBX-009; FR-008, FR-012, FR-015 ([005](../005-delegated-mailboxes/spec.md)) |
| `src/Mailbox/Engine/SubmitTokenRejected.php` | Send (2.5.0) | FR-MBX-009; FR-008, FR-012, FR-015 ([005](../005-delegated-mailboxes/spec.md)) |
| `src/Mailbox/Engine/SendOnlyMailboxGatewayInterface.php` | Send (phase B2) | FR-MBX-011; FR-019 ([005](../005-delegated-mailboxes/spec.md)) |
| `src/Mailbox/Engine/PreparedMessage.php` | Send (phase B2) | FR-MBX-011; FR-019 ([005](../005-delegated-mailboxes/spec.md)) |
| `src/Mailbox/Engine/SubmittedMessage.php` | Send (phase B2) | FR-MBX-011; FR-019 ([005](../005-delegated-mailboxes/spec.md)) |
| `src/Mailbox/Exception/MailboxSendFailed.php` | Send (2.5.0) | FR-MBX-009; FR-008, FR-012, FR-015 ([005](../005-delegated-mailboxes/spec.md)) |
| `src/Mailbox/Exception/MailboxSendInProgress.php` | Send (2.5.0) | FR-MBX-009; FR-008, FR-012, FR-015 ([005](../005-delegated-mailboxes/spec.md)) |
| `src/Mailbox/Exception/MailboxSendKeyConflict.php` | Send (2.5.0) | FR-MBX-009; FR-008, FR-012, FR-015 ([005](../005-delegated-mailboxes/spec.md)) |
| `src/Mailbox/Exception/MailboxSendOutcomeUnknown.php` | Send (2.5.0) | FR-MBX-009; FR-008, FR-012, FR-015 ([005](../005-delegated-mailboxes/spec.md)) |
| `src/Mailbox/Infrastructure/Command/MailboxPurgeCommand.php` | Send (2.5.0) | FR-MBX-009; FR-008, FR-012, FR-015 ([005](../005-delegated-mailboxes/spec.md)) |
| `src/Mailbox/Infrastructure/Messenger/DeliverSentCopy.php` | Send (2.5.0) | FR-MBX-009; FR-008, FR-012, FR-015 ([005](../005-delegated-mailboxes/spec.md)) |
| `src/Mailbox/Infrastructure/Messenger/ReconcileMailboxSend.php` | Send (2.5.0) | FR-MBX-009; FR-008, FR-012, FR-015 ([005](../005-delegated-mailboxes/spec.md)) |
| `src/Mailbox/Service/MailboxSenderInterface.php` | Send (2.5.0) | FR-MBX-009; FR-008, FR-012, FR-015 ([005](../005-delegated-mailboxes/spec.md)) |
| `src/Mailbox/Value/OutgoingAttachment.php` | Send (2.5.0) | FR-MBX-009; FR-008, FR-012, FR-015 ([005](../005-delegated-mailboxes/spec.md)) |
| `src/Mailbox/Value/OutgoingKind.php` | Send (2.5.0) | FR-MBX-009; FR-008, FR-012, FR-015 ([005](../005-delegated-mailboxes/spec.md)) |
| `src/Mailbox/Value/OutgoingMailboxMail.php` | Send (2.5.0) | FR-MBX-009; FR-008, FR-012, FR-015 ([005](../005-delegated-mailboxes/spec.md)) |
| `src/Mailbox/Value/SentMessage.php` | Send (2.5.0) | FR-MBX-009; FR-008, FR-012, FR-015 ([005](../005-delegated-mailboxes/spec.md)) |

## Delegated mailboxes: value objects

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/Mailbox/Value/AttachmentKind.php` | Value objects (2.5.0) | FR-MBX-003; FR-001, FR-006, FR-015 ([005](../005-delegated-mailboxes/spec.md)) |
| `src/Mailbox/Value/AttachmentRef.php` | Value objects (2.5.0) | FR-MBX-003; FR-001, FR-006, FR-015 ([005](../005-delegated-mailboxes/spec.md)) |
| `src/Mailbox/Value/DisconnectResult.php` | Value objects (2.5.0) | FR-MBX-003; FR-001, FR-006, FR-015 ([005](../005-delegated-mailboxes/spec.md)) |
| `src/Mailbox/Value/ExclusionDecision.php` | Value objects (2.5.0) | FR-MBX-003; FR-001, FR-006, FR-015 ([005](../005-delegated-mailboxes/spec.md)) |
| `src/Mailbox/Value/FetchedAttachment.php` | Value objects (2.5.0) | FR-MBX-003; FR-001, FR-006, FR-015 ([005](../005-delegated-mailboxes/spec.md)) |
| `src/Mailbox/Value/FolderRef.php` | Value objects (2.5.0) | FR-MBX-003; FR-001, FR-006, FR-015 ([005](../005-delegated-mailboxes/spec.md)) |
| `src/Mailbox/Value/FolderSyncState.php` | Value objects (2.5.0) | FR-MBX-003; FR-001, FR-006, FR-015 ([005](../005-delegated-mailboxes/spec.md)) |
| `src/Mailbox/Value/GrantFailureReason.php` | Value objects (2.5.0) | FR-MBX-003; FR-001, FR-006, FR-015 ([005](../005-delegated-mailboxes/spec.md)) |
| `src/Mailbox/Value/MailboxAccessToken.php` | Value objects (2.5.0) | FR-MBX-003; FR-001, FR-006, FR-015 ([005](../005-delegated-mailboxes/spec.md)) |
| `src/Mailbox/Value/MailboxId.php` | Value objects (2.5.0) | FR-MBX-003; FR-001, FR-006, FR-015 ([005](../005-delegated-mailboxes/spec.md)) |
| `src/Mailbox/Value/MailboxProvider.php` | Value objects (2.5.0) | FR-MBX-003; FR-001, FR-006, FR-015 ([005](../005-delegated-mailboxes/spec.md)) |
| `src/Mailbox/Value/MailboxSettings.php` | Value objects (2.5.0) | FR-MBX-003; FR-001, FR-006, FR-015 ([005](../005-delegated-mailboxes/spec.md)) |
| `src/Mailbox/Value/MailboxStatus.php` | Value objects (2.5.0) | FR-MBX-003; FR-001, FR-006, FR-015 ([005](../005-delegated-mailboxes/spec.md)) |
| `src/Mailbox/Value/MailboxSyncStatus.php` | Value objects (2.5.0) | FR-MBX-003; FR-001, FR-006, FR-015 ([005](../005-delegated-mailboxes/spec.md)) |
| `src/Mailbox/Value/MessageEnvelope.php` | Value objects (2.5.0) | FR-MBX-003; FR-001, FR-006, FR-015 ([005](../005-delegated-mailboxes/spec.md)) |
| `src/Mailbox/Value/MessageOrigin.php` | Value objects (2.5.0) | FR-MBX-003; FR-001, FR-006, FR-015 ([005](../005-delegated-mailboxes/spec.md)) |
| `src/Mailbox/Value/ProviderAttachmentId.php` | Value objects (2.5.0) | FR-MBX-003; FR-001, FR-006, FR-015 ([005](../005-delegated-mailboxes/spec.md)) |
| `src/Mailbox/Value/ProviderCall.php` | Value objects (2.5.0) | FR-MBX-003; FR-001, FR-006, FR-015 ([005](../005-delegated-mailboxes/spec.md)) |
| `src/Mailbox/Value/ProviderMessageId.php` | Value objects (2.5.0) | FR-MBX-003; FR-001, FR-006, FR-015 ([005](../005-delegated-mailboxes/spec.md)) |
| `src/Mailbox/Value/RemovalReason.php` | Value objects (2.5.0) | FR-MBX-003; FR-001, FR-006, FR-015 ([005](../005-delegated-mailboxes/spec.md)) |
| `src/Mailbox/Value/StoreOutcome.php` | Value objects (2.5.0) | FR-MBX-003; FR-001, FR-006, FR-015 ([005](../005-delegated-mailboxes/spec.md)) |
| `src/Mailbox/Value/SyncedMessage.php` | Value objects (2.5.0) | FR-MBX-003; FR-001, FR-006, FR-015 ([005](../005-delegated-mailboxes/spec.md)) |
| `src/Mailbox/Value/ThreadKey.php` | Value objects (2.5.0) | FR-MBX-003; FR-001, FR-006, FR-015 ([005](../005-delegated-mailboxes/spec.md)) |

## Delegated mailboxes: Microsoft Graph provider

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/Mailbox/Graph/DelegatedGraphCredential.php` | Microsoft graph provider (2.5.0) | FR-MBX-004; FR-003, FR-014 ([005](../005-delegated-mailboxes/spec.md)) |
| `src/Mailbox/Graph/GraphMailboxGateway.php` | Microsoft graph provider (2.5.0) | FR-MBX-004; FR-003, FR-014 ([005](../005-delegated-mailboxes/spec.md)) |

## Delegated mailboxes: Gmail provider (send-only, phase B2)

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/Mailbox/Gmail/GmailApiClient.php` | Gmail provider (phase B2) | FR-MBX-011; FR-019 ([005](../005-delegated-mailboxes/spec.md)) |
| `src/Mailbox/Gmail/GmailMailboxGateway.php` | Gmail provider (phase B2) | FR-MBX-011; FR-019 ([005](../005-delegated-mailboxes/spec.md)) |
| `src/Mailbox/Gmail/GmailScopes.php` | Gmail provider (phase B2) | FR-MBX-011; FR-019 ([005](../005-delegated-mailboxes/spec.md)) |

## Delegated mailboxes: HTML sanitiser

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/Mailbox/Html/MailHtmlSanitizer.php` | Html sanitiser (2.5.0) | FR-MBX-005; FR-009 ([005](../005-delegated-mailboxes/spec.md)) |
| `src/Mailbox/Html/RemoteImages.php` | Html sanitiser (2.5.0) | FR-MBX-005; FR-009 ([005](../005-delegated-mailboxes/spec.md)) |

## Delegated mailboxes: webhook

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/Mailbox/Infrastructure/Webhook/MailboxGraphWebhookController.php` | Webhook (2.5.0) | FR-MBX-006; FR-010 ([005](../005-delegated-mailboxes/spec.md)) |
| `src/Mailbox/Infrastructure/Webhook/MailboxWebhookRouteLoader.php` | Webhook (2.5.0) | FR-MBX-006; FR-010 ([005](../005-delegated-mailboxes/spec.md)) |

## Delegated mailboxes: Messenger and commands

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/Mailbox/Infrastructure/Command/MailboxSchemaCommand.php` | Messenger and commands (2.5.0) | FR-MBX-007; FR-004, FR-011, FR-013 ([005](../005-delegated-mailboxes/spec.md)) |
| `src/Mailbox/Infrastructure/Command/MailboxStatusCommand.php` | Messenger and commands (2.5.0) | FR-MBX-007; FR-004, FR-011, FR-013 ([005](../005-delegated-mailboxes/spec.md)) |
| `src/Mailbox/Infrastructure/Command/MailboxSubscriptionsCommand.php` | Messenger and commands (2.5.0) | FR-MBX-007; FR-004, FR-011, FR-013 ([005](../005-delegated-mailboxes/spec.md)) |
| `src/Mailbox/Infrastructure/Command/MailboxSweepCommand.php` | Messenger and commands (2.5.0) | FR-MBX-007; FR-004, FR-011, FR-013 ([005](../005-delegated-mailboxes/spec.md)) |
| `src/Mailbox/Infrastructure/Messenger/FetchMailboxMessage.php` | Messenger and commands (2.5.0) | FR-MBX-007; FR-004, FR-011, FR-013 ([005](../005-delegated-mailboxes/spec.md)) |
| `src/Mailbox/Infrastructure/Messenger/MailboxMessageHandler.php` | Messenger and commands (2.5.0) | FR-MBX-007; FR-004, FR-011, FR-013 ([005](../005-delegated-mailboxes/spec.md)) |
| `src/Mailbox/Infrastructure/Messenger/RenewMailboxSubscriptions.php` | Messenger and commands (2.5.0) | FR-MBX-007; FR-004, FR-011, FR-013 ([005](../005-delegated-mailboxes/spec.md)) |
| `src/Mailbox/Infrastructure/Messenger/SyncMailboxFolder.php` | Messenger and commands (2.5.0) | FR-MBX-007; FR-004, FR-011, FR-013 ([005](../005-delegated-mailboxes/spec.md)) |

## Delegated mailboxes: store

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/Mailbox/Infrastructure/Store/MailboxSchemaListener.php` | Store (2.5.0) | FR-MBX-008; FR-012, FR-015 ([005](../005-delegated-mailboxes/spec.md)) |
| `src/Mailbox/Infrastructure/Store/MailboxStore.php` | Store (2.5.0) | FR-MBX-008; FR-012, FR-015 ([005](../005-delegated-mailboxes/spec.md)) |

## Delegated mailboxes: events and exceptions

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/Mailbox/Event/MailboxFolderSyncFailedEvent.php` | Events and exceptions (2.5.0) | FR-MBX-010; FR-013 ([005](../005-delegated-mailboxes/spec.md)) |
| `src/Mailbox/Event/MailboxGrantFailedEvent.php` | Events and exceptions (2.5.0) | FR-MBX-010; FR-013 ([005](../005-delegated-mailboxes/spec.md)) |
| `src/Mailbox/Event/MailboxResyncedEvent.php` | Events and exceptions (2.5.0) | FR-MBX-010; FR-013 ([005](../005-delegated-mailboxes/spec.md)) |
| `src/Mailbox/Exception/MailboxException.php` | Events and exceptions (2.5.0) | FR-MBX-010; FR-013 ([005](../005-delegated-mailboxes/spec.md)) |
| `src/Mailbox/Exception/MailboxGrantFailure.php` | Events and exceptions (2.5.0) | FR-MBX-010; FR-013 ([005](../005-delegated-mailboxes/spec.md)) |
| `src/Mailbox/Exception/MailboxNotActive.php` | Events and exceptions (2.5.0) | FR-MBX-010; FR-013 ([005](../005-delegated-mailboxes/spec.md)) |
| `src/Mailbox/Exception/MailboxProviderFailure.php` | Events and exceptions (2.5.0) | FR-MBX-010; FR-013 ([005](../005-delegated-mailboxes/spec.md)) |
| `src/Mailbox/Exception/MailboxTokenUnavailable.php` | Events and exceptions (2.5.0) | FR-MBX-010; FR-013 ([005](../005-delegated-mailboxes/spec.md)) |

## Stored e-mail templates

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/Template/Engine/PlaceholderResolver.php` | Stored e-mail templates | FR-TPL-008 ([006](../006-stored-templates/spec.md)) |
| `src/Template/Engine/TemplateLibrary.php` | Stored e-mail templates | FR-TPL-005, FR-TPL-006 ([006](../006-stored-templates/spec.md)) |
| `src/Template/Engine/TemplatePersonalData.php` | Stored e-mail templates | FR-TPL-011 ([006](../006-stored-templates/spec.md)) |
| `src/Template/Engine/TemplateRenderer.php` | Stored e-mail templates | FR-TPL-009 ([006](../006-stored-templates/spec.md)) |
| `src/Template/Event/TemplateDeletedEvent.php` | Stored e-mail templates | FR-TPL-010 ([006](../006-stored-templates/spec.md)) |
| `src/Template/Exception/InvalidTemplate.php` | Stored e-mail templates | FR-TPL-003, FR-TPL-006 ([006](../006-stored-templates/spec.md)) |
| `src/Template/Exception/TemplateAccessDenied.php` | Stored e-mail templates | FR-TPL-005 ([006](../006-stored-templates/spec.md)) |
| `src/Template/Exception/TemplateException.php` | Stored e-mail templates | FR-TPL-005 ([006](../006-stored-templates/spec.md)) |
| `src/Template/Exception/TemplateKeyTaken.php` | Stored e-mail templates | FR-TPL-006, FR-TPL-012 ([006](../006-stored-templates/spec.md)) |
| `src/Template/Exception/TemplateNotFound.php` | Stored e-mail templates | FR-TPL-005 ([006](../006-stored-templates/spec.md)) |
| `src/Template/Exception/TemplateVersionConflict.php` | Stored e-mail templates | FR-TPL-006, FR-TPL-012 ([006](../006-stored-templates/spec.md)) |
| `src/Template/Infrastructure/Command/TemplateSchemaCommand.php` | Stored e-mail templates | FR-TPL-002 ([006](../006-stored-templates/spec.md)) |
| `src/Template/Infrastructure/Store/TemplateSchemaListener.php` | Stored e-mail templates | FR-TPL-002 ([006](../006-stored-templates/spec.md)) |
| `src/Template/Infrastructure/Store/TemplateStore.php` | Stored e-mail templates | FR-TPL-002, FR-TPL-012 ([006](../006-stored-templates/spec.md)) |
| `src/Template/Placeholder/PlaceholderContext.php` | Stored e-mail templates | FR-TPL-007 ([006](../006-stored-templates/spec.md)) |
| `src/Template/Placeholder/PlaceholderDefinition.php` | Stored e-mail templates | FR-TPL-007 ([006](../006-stored-templates/spec.md)) |
| `src/Template/Placeholder/PlaceholderProviderInterface.php` | Stored e-mail templates | FR-TPL-007 ([006](../006-stored-templates/spec.md)) |
| `src/Template/Port/DenyAllTemplatePermission.php` | Stored e-mail templates | FR-TPL-004 ([006](../006-stored-templates/spec.md)) |
| `src/Template/Port/TemplateOwnerResolverInterface.php` | Stored e-mail templates | FR-TPL-004, FR-TPL-011 ([006](../006-stored-templates/spec.md)) |
| `src/Template/Port/TemplatePermissionInterface.php` | Stored e-mail templates | FR-TPL-004 ([006](../006-stored-templates/spec.md)) |
| `src/Template/Service/TemplateLibraryInterface.php` | Stored e-mail templates | FR-TPL-006 ([006](../006-stored-templates/spec.md)) |
| `src/Template/Service/TemplatePersonalDataInterface.php` | Stored e-mail templates | FR-TPL-011 ([006](../006-stored-templates/spec.md)) |
| `src/Template/Service/TemplateRendererInterface.php` | Stored e-mail templates | FR-TPL-009 ([006](../006-stored-templates/spec.md)) |
| `src/Template/Value/RenderedTemplate.php` | Stored e-mail templates | FR-TPL-009 ([006](../006-stored-templates/spec.md)) |
| `src/Template/Value/StoredTemplate.php` | Stored e-mail templates | FR-TPL-006 ([006](../006-stored-templates/spec.md)) |
| `src/Template/Value/TemplateActor.php` | Stored e-mail templates | FR-TPL-004 ([006](../006-stored-templates/spec.md)) |
| `src/Template/Value/TemplateDraft.php` | Stored e-mail templates | FR-TPL-003 ([006](../006-stored-templates/spec.md)) |
| `src/Template/Value/TemplateErasureResult.php` | Stored e-mail templates | FR-TPL-011 ([006](../006-stored-templates/spec.md)) |
| `src/Template/Value/TemplateKind.php` | Stored e-mail templates | FR-TPL-002 ([006](../006-stored-templates/spec.md)) |
| `src/Template/Value/TemplatePermission.php` | Stored e-mail templates | FR-TPL-004 ([006](../006-stored-templates/spec.md)) |
| `src/Template/Value/TemplateScope.php` | Stored e-mail templates | FR-TPL-002 ([006](../006-stored-templates/spec.md)) |
| `src/Template/Value/TemplateSummary.php` | Stored e-mail templates | FR-TPL-006 ([006](../006-stored-templates/spec.md)) |

## Coverage summary

| Category | Files | Mapped |
| --- | ---: | ---: |
| Bundle and dependency injection | 12 | 12 |
| Settings | 6 | 6 |
| DSN and sender | 18 | 18 |
| Delivery | 5 | 5 |
| Test e-mail and Resend | 7 | 7 |
| Sent-mail log | 14 | 14 |
| Readiness | 6 | 6 |
| Admin UI | 20 | 20 |
| Events | 5 | 5 |
| Personal data (GDPR) | 15 | 15 |
| Branded e-mail | 7 | 7 |
| Microsoft Graph sending | 24 | 24 |
| Microsoft Graph inbound | 28 | 28 |
| Open and click tracking | 30 | 30 |
| Concurrency | 3 | 3 |
| Delegated mailboxes: host ports and services | 10 | 10 |
| Delegated mailboxes: sync engine | 11 | 11 |
| Delegated mailboxes: send | 21 | 21 |
| Delegated mailboxes: value objects | 23 | 23 |
| Delegated mailboxes: Microsoft Graph provider | 2 | 2 |
| Delegated mailboxes: Gmail provider (send-only, phase B2) | 3 | 3 |
| Delegated mailboxes: HTML sanitiser | 2 | 2 |
| Delegated mailboxes: webhook | 2 | 2 |
| Delegated mailboxes: Messenger and commands | 8 | 8 |
| Delegated mailboxes: store | 2 | 2 |
| Delegated mailboxes: events and exceptions | 8 | 8 |
| Stored e-mail templates | 32 | 32 |
| **Total** | **324** | **324** |
