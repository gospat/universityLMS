<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'ULMS auth';
$string['generalsettings'] = 'General settings';
$string['generalsettingsdesc'] = 'Configure authentication-related options for ULMS.';
$string['enablerolelanding'] = 'Enable role-based landing pages';
$string['enablerolelandingdesc'] = 'Redirect users to dashboards based on their primary role after login.';
$string['enforceadminmfa'] = 'Enforce stronger admin authentication';
$string['enforceadminmfadesc'] = 'Use this flag to prepare stronger authentication controls for privileged users.';
$string['adminportalaudience'] = 'Administrators, ICT administrators, college administrators, department administrators, and other approved institutional operators.';
$string['adminportaldesc'] = 'Access the centralized admin workspace reserved for privileged institutional operations, user management, configuration, and reporting.';
$string['adminportaleyebrow'] = 'Admin access';
$string['adminportaltitle'] = 'Admin portal';
$string['activationeyebrow'] = 'Account activation';
$string['activationformdesc'] = 'Create your password to activate your ULMS account and continue to your assigned portal.';
$string['activationformheading'] = 'Activate your account';
$string['activationheading'] = 'Activate your ULMS account';
$string['activationhelpbody'] = 'If the activation link has expired, request a new password reset link or contact support for assistance.';
$string['activationhelpheading'] = 'Need a fresh access link?';
$string['activationintro'] = 'Your ULMS account has been created. Set a secure password to activate access.';
$string['activationnote'] = 'Activation links are single-use and expire automatically for your security.';
$string['activationcompletesignin'] = 'Your account has been activated successfully. Sign in to continue.';
$string['activationrequestnewlink'] = 'Request a new password reset link';
$string['activationtokenexpired'] = 'This activation link has expired. Request a new access link and complete setup within {$a} minutes.';
$string['activationtokeninvalid'] = 'This activation link is invalid or has already been used.';
$string['alreadyauthenticatedredirect'] = 'You are already signed in. Redirecting to your authorised portal.';
$string['invalidportalroute'] = 'The requested portal route is not available.';
$string['lecturerportalaudience'] = 'Lecturers and teaching staff assigned to course delivery.';
$string['lecturerportaldesc'] = 'Open your teaching dashboard, course delivery tools, grading queue, and lecturer-only academic workspace.';
$string['lecturerportaleyebrow'] = 'Lecturer access';
$string['lecturerportaltitle'] = 'Lecturer portal';
$string['loginfieldrequired'] = '{$a} is required.';
$string['portallandingdesc'] = 'Choose the portal that matches your institutional role so you always sign in through the correct interface.';
$string['portallandingeyebrow'] = 'Unified sign-in';
$string['portallandinghelpdesc'] = 'Use the portal that matches your account role, keep sign-in details private, and recover access through the secure password flow when needed.';
$string['portallandinghelpheading'] = 'Sign-in support';
$string['portallandingnote'] = 'This is the only public ULMS sign-in entry. Select your portal below to continue.';
$string['portallandingpaneldesc'] = 'Each portal leads to a separate role-specific sign-in flow with its own protected interface.';
$string['portallandingpaneltitle'] = 'Select your portal';
$string['portallandingpasswordresetcta'] = 'Need help with password recovery?';
$string['portallandingtitle'] = 'Sign in to University Learning Management System';
$string['passwordresetcontactsupportfallback'] = 'the support team';
$string['passwordreseteyebrow'] = 'Account recovery';
$string['passwordresetformdesc'] = 'Enter the username or institutional email address linked to your account.';
$string['passwordresetformheading'] = 'Recover access';
$string['passwordresetgenericnotice'] = 'If the account details you entered match an active account, password recovery instructions will be sent shortly.';
$string['passwordresetheading'] = 'Secure password recovery';
$string['passwordresetcompleteeyebrow'] = 'Reset password';
$string['passwordresetcompleteformdesc'] = 'Choose a new password that meets the current ULMS password policy.';
$string['passwordresetcompleteformheading'] = 'Set a new password';
$string['passwordresetcompleteheading'] = 'Reset your ULMS password';
$string['passwordresetcompleteintro'] = 'Complete your password reset using the secure one-time link from your email.';
$string['passwordresetcompletenote'] = 'This reset link is single-use and will expire automatically.';
$string['passwordresetcompletesignin'] = 'Your password has been reset successfully. Sign in with your new password.';
$string['passwordresetemailbody'] = 'Hello {$a->firstname},

We received a request to reset the password for your {$a->sitename} account.

Username: {$a->username}
Reset password: {$a->resetlink}
Sign in after reset: {$a->signinurl}

If you did not request this password reset, you can ignore this email and your current password will remain unchanged.

{$a->supportsignature}';
$string['passwordresetemailsubject'] = '{$a}: reset your ULMS password';
$string['passwordresethelpbody'] = 'If recovery email delivery is unavailable, contact {$a} for manual assistance.';
$string['passwordresethelpheading'] = 'Need help?';
$string['passwordresethelpsupport'] = 'For your security, recovery requests use one-time links and may be rate-limited if too many requests are submitted in a short period.';
$string['passwordresetidentifierhelp'] = 'Use the same username or verified institutional email address you use to sign in.';
$string['passwordresetidentifierinvalid'] = 'Enter a valid username or email address.';
$string['passwordresetidentifierlabel'] = 'Username or email address';
$string['passwordresetidentifierrequired'] = 'Enter your username or email address to continue.';
$string['passwordresetintro'] = 'Request a one-time password reset link using your verified account details.';
$string['passwordresetlink'] = 'Forgot your password?';
$string['passwordresetratelimitednotice'] = 'If the account details you entered match an active account, recovery instructions will be sent shortly. Please wait a few minutes before trying again.';
$string['passwordresetreturnlanding'] = 'Back to portal selection';
$string['passwordresetreturnportal'] = 'Back to {$a} sign-in';
$string['passwordresetreturnsignin'] = 'Open unified sign-in';
$string['passwordresetsecuritynote'] = 'For privacy, the recovery screen never confirms whether a username or email address exists in the system.';
$string['passwordresetsubmit'] = 'Send recovery instructions';
$string['passwordresetsubmitting'] = 'Sending instructions...';
$string['passwordresettokenexpired'] = 'This password reset link has expired. Request a new one and complete the reset within {$a} minutes.';
$string['passwordresettokeninvalid'] = 'This password reset link is invalid or has already been used.';
$string['passwordresettemporarilyunavailable'] = 'Password recovery is temporarily unavailable. Please try again later or contact {$a}.';
$string['portalaudience'] = 'Access scope: {$a}';
$string['portalaccessnote'] = 'Use the correct portal for your institutional role to avoid access issues after sign-in.';
$string['portalalternateheading'] = 'Other portals';
$string['portalbacktolanding'] = 'Back to portal selection';
$string['portalcontinuecta'] = 'Open portal';
$string['portalhidepassword'] = 'Hide password';
$string['portalloginactivationrequired'] = 'Your account requires activation. Please check your email for the activation link.';
$string['portalpasswordhelp'] = 'Use your current institutional password. Passwords are case-sensitive.';
$string['portalloginfailure'] = 'We could not complete sign-in right now. Please try again in a moment.';
$string['portallogininvalid'] = 'Invalid username or password.';
$string['portalloginsuspended'] = 'Your account is currently suspended. Please contact support.';
$string['portalrememberme'] = 'Remember me on this device';
$string['portalremembermehelp'] = 'Only use this option on a trusted personal device.';
$string['portalsecurityheading'] = 'Security and support';
$string['portalsecurityitempassword'] = 'Passwords are case-sensitive and should never be shared.';
$string['portalsecurityitemportal'] = 'Use the correct role portal so you land in the right workspace after sign-in.';
$string['portalsecurityitemrecovery'] = 'Use password recovery if you no longer have access to your account.';
$string['portalshowpassword'] = 'Show password';
$string['portalloginloading'] = 'Signing in to {$a}...';
$string['portalloginbutton'] = 'Sign in to {$a}';
$string['portallogindesc'] = 'Enter your credentials to continue to the {$a}.';
$string['portalloginheading'] = '{$a} sign-in';
$string['portalloginhelpheading'] = 'Need help signing in?';
$string['portalloginhelpdesc'] = 'Use your verified username or email address, double-check the selected portal, and reset your password if you cannot remember it.';
$string['portalmatchedcta'] = 'Continue to {$a}';
$string['portalrolemismatch'] = 'This sign-in page is for {$a->expected}. Your account belongs to {$a->actual}. Use the correct portal to continue.';
$string['portalroutingdocdesc'] = 'Configuration reference for role-specific homepage links, portal login routes, and dashboard access control.';
$string['portalusername'] = 'Username or email address';
$string['portalusernamehelp'] = 'Enter the username or verified institutional email address linked to your account.';
$string['studentportalaudience'] = 'Students and learner accounts.';
$string['studentportaldesc'] = 'Sign in to your learning dashboard, enrolled courses, assignment deadlines, and student-only academic calendar.';
$string['studentportaleyebrow'] = 'Student access';
$string['studentportaltitle'] = 'Student portal';
$string['superadminportalaudience'] = 'Moodle site administrators and platform owners responsible for institution-wide control.';
$string['superadminportaldesc'] = 'Sign in to the platform operations workspace for system health, integrations, security controls, institutional governance, and audit reporting.';
$string['superadminportaleyebrow'] = 'Platform operations';
$string['superadminportaltitle'] = 'Super admin portal';
$string['ulms_auth:managerolelanding'] = 'Manage ULMS authentication landing rules';
$string['ulms_auth:viewlanding'] = 'View ULMS landing pages';
$string['privacy:metadata'] = 'The ULMS auth plugin stores authentication configuration only.';
