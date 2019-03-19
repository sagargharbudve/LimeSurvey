<?php if (!defined('BASEPATH')) {
    exit('No direct script access allowed');
}
/*
* LimeSurvey
* Copyright (C) 2007-2011 The LimeSurvey Project Team / Carsten Schmitz
* All rights reserved.
* License: GNU/GPL License v2 or later, see LICENSE.php
* LimeSurvey is free software. This version may have been modified pursuant
* to the GNU General Public License, and as distributed it includes or
* is derivative of works licensed under the GNU General Public License or
* other free or open source software licenses.
* See COPYRIGHT.php for copyright notices and details.
*
*/

/**
* register
*
* @package LimeSurvey
* @copyright 2011
* @access public
*/
class RegisterController extends LSYii_Controller
{

    /* @var string : Default layout when using render : leave at bare actually : just send content */
    public $layout = 'survey';
    /* @var string the template name to be used when using layout */
    public $sTemplate;
    /* @var string[] Replacement data when use templatereplace function in layout, @see templatereplace $replacements */
    public $aReplacementData = array();
    /* @var array Global data when use templatereplace function  in layout, @see templatereplace $redata */
    public $aGlobalData = array();


    /**
     * The array of errors to be displayed
     */
    private $aRegisterErrors;
    /**
     * The message to be shown, is not null: default form not shown
     */
    private $sMessage;
    /**
     * The message to diplay after sending the register email
     */
    private $sMailMessage;

    public function actions()
    {
        return array(
            'captcha' => array(
                'class' => 'CaptchaExtendedAction',
                'mode'=>CaptchaExtendedAction::MODE_MATH
            )
        );
    }

    public function actionAJAXRegisterForm($surveyid)
    {
        Yii::app()->loadHelper('database');
        Yii::app()->loadHelper('replacements');
        $redata = compact(array_keys(get_defined_vars()));
        $iSurveyId = $surveyid;
        $oSurvey = Survey::model()->find('sid=:sid', array(':sid' => $iSurveyId));
        if (!$oSurvey) {
            throw new CHttpException(404, "The survey in which you are trying to participate does not seem to exist. It may have been deleted or the link you were given is outdated or incorrect.");
        }
        // Don't test if survey allow registering .....
        $sLanguage = Yii::app()->request->getParam('lang', $oSurvey->language);
        Yii::app()->setLanguage($sLanguage);

        $thistpl = getTemplatePath($oSurvey->template);
        $data['sid'] = $iSurveyId;
        $data['startdate'] = $oSurvey->startdate;
        $data['enddate'] = $oSurvey->expires;
        $data['thissurvey'] = getSurveyInfo($iSurveyId, $oSurvey->language);
        echo self::ajaxGetRegisterForm($iSurveyId);
        Yii::app()->end();
    }

    /**
     * Default action register
     * Process register form data and take appropriate action
     * @param $sid Survey Id to register
     * @param $aRegisterErrors array of errors when try to register
     * @return
     */
    public function actionIndex($sid = null)
    {

        if (!is_null($sid)) {
            $iSurveyId = $sid;
        } else {
            $iSurveyId = Yii::app()->request->getPost('sid');
        }

        $oSurvey = Survey::model()->find("sid=:sid", array(':sid'=>$iSurveyId));
        /* Throw 404 if needed */
        $sLanguage = Yii::app()->request->getParam('lang', Yii::app()->getConfig('defaultlang'));
        Yii::app()->setLanguage($sLanguage);
        if (!$oSurvey) {
            throw new CHttpException(404, "The survey in which you are trying to participate does not seem to exist. It may have been deleted or the link you were given is outdated or incorrect.");
        } elseif ($oSurvey->allowregister != 'Y' || !tableExists("{{tokens_{$iSurveyId}}}")) {
            throw new CHttpException(404, "The survey in which you are trying to register don't accept registration. It may have been updated or the link you were given is outdated or incorrect.");
        } elseif (!is_null($oSurvey->expires) && $oSurvey->expires < dateShift(date("Y-m-d H:i:s"), "Y-m-d H:i", Yii::app()->getConfig('timeadjust'))) {
            $this->redirect(array('survey/index', 'sid'=>$iSurveyId, 'lang'=>$sLanguage));
        }
        /* Fix language according to existing language in survey */
        if (!in_array($sLanguage, $oSurvey->getAllLanguages())) {
            $sLanguage = $oSurvey->language;
            Yii::app()->setLanguage($sLanguage);
        }

        $event = new PluginEvent('beforeRegister');
        $event->set('surveyid', $iSurveyId);
        $event->set('lang', $sLanguage);
        App()->getPluginManager()->dispatchEvent($event);

        $this->sMessage = $event->get('sMessage');
        $this->aRegisterErrors = $event->get('aRegisterErrors');
        $iTokenId = $event->get('iTokenId');
        // Test if we come from register form (and submit)
        if ((Yii::app()->request->getPost('register')) && !$iTokenId) {
            self::getRegisterErrors($iSurveyId);
            if (empty($this->aRegisterErrors)) {
                $iTokenId = self::getTokenId($iSurveyId);
            }
        }
        if (empty($this->aRegisterErrors) && $iTokenId && $this->sMessage === null) {
            $directLogin = $event->get('directLogin', false);
            self::sendRegistrationEmail($iSurveyId, $iTokenId);
            if ($directLogin == true) {
                $oToken = Token::model($iSurveyId)->findByPk($iTokenId);
                $redirectUrl = Yii::app()->getController()->createUrl('/survey/', array('sid' => $iSurveyId,'token' => $oToken->token, 'lang'=>$sLanguage));
                Yii::app()->getController()->redirect($redirectUrl);
                Yii::app()->end();
            }
            
            self::display($iSurveyId, $iTokenId, 'register_success');
            Yii::app()->end();
        }

        // Display the page
        self::display($iSurveyId, null, 'register_form');
    }

    /**
     * Validate a register form
     * @param $iSurveyId Survey Id to register
     * @return array of errors when try to register (empty array => no error)
     */
    public function getRegisterErrors($iSurveyId)
    {
        $aSurveyInfo = getSurveyInfo($iSurveyId, App()->language);

        // Check the security question's answer
        if (isCaptchaEnabled('registrationscreen', $aSurveyInfo['usecaptcha'])) {
            $sLoadSecurity = Yii::app()->request->getPost('loadsecurity', '');
            $captcha = Yii::app()->getController()->createAction("captcha");
            $captchaCorrect = $captcha->validate($sLoadSecurity, false);

            if (!$captchaCorrect) {
                $this->aRegisterErrors[] = gT("Your answer to the security question was not correct - please try again.");
            }
        }

        $aFieldValue = $this->getFieldValue($iSurveyId);
        $aRegisterAttributes = $this->getExtraAttributeInfo($iSurveyId);

        //Check that the email is a valid style address
        if ($aFieldValue['sEmail'] == "") {
            $this->aRegisterErrors[] = gT("You must enter a valid email. Please try again.");
        } elseif (!validateEmailAddress($aFieldValue['sEmail'])) {
            $this->aRegisterErrors[] = gT("The email you used is not valid. Please try again.");
        }
        //Check and validate attribute
        foreach ($aRegisterAttributes as $key => $aAttribute) {
            if ($aAttribute['show_register'] == 'Y' && $aAttribute['mandatory'] == 'Y' && empty($aFieldValue['aAttribute'][$key])) {
                $this->aRegisterErrors[] = sprintf(gT("%s cannot be left empty."), $aAttribute['caption']);
            }
        }
    }

    /**
     * Creates the array for the registration success page
     *
     * @param Integer $iSurveyId The survey id
     * @param Integer $iTokenId The token id
     * 
     * @return Array The rendereable array
     */
    public function getRegisterSuccess($iSurveyId, $iTokenId)
    {
        $oSurvey = Survey::model()->findByPk($iSurveyId);
        
        $oToken = Token::model($iSurveyId)->findByPk($iTokenId);
        
        $aData['active'] = $oSurvey->active;        
        $aData['iSurveyId'] = $iSurveyId;
        $aData['sLanguage'] = App()->language;
        $aData['sFirstName'] = $oToken->firstname;
        $aData['sLastName'] = $oToken->lastname;
        $aData['sEmail'] = $oToken->email;
        $aData['thissurvey'] = $oSurvey->attributes;

        return $aData;
    }

    /**
     * Create the array to render the registration form
     * Takes eventual changes through plugins into account
     *
     * @param Integer $iSurveyId The surey id
     * 
     * @return Array The rendereable array
     */
    public function getRegisterForm($iSurveyId)
    {
        $oSurvey = Survey::model()->findByPk($iSurveyId);

        // Event to replace register form
        $event = new PluginEvent('beforeRegisterForm');
        $event->set('surveyid', $iSurveyId);
        $event->set('lang', App()->language);
        $event->set('aRegistersErrors', $this->aRegisterErrors);
        App()->getPluginManager()->dispatchEvent($event);
        // Allow adding error or replace error with plugin ?
        $this->aRegisterErrors = $event->get('aRegistersErrors');
        $registerFormEvent = array();
        if (!is_null($event->get('registerForm'))) {
            $registerFormEvent = $event->get('registerForm');
            if (!isset($registerFormEvent['append']) || $registerFormEvent['append'] == false) {
                return $event->get('registerForm');
            }
        }
        $aFieldValue = $this->getFieldValue($iSurveyId);
        $aRegisterAttributes = $this->getExtraAttributeInfo($iSurveyId);

        $aData['iSurveyId'] = $iSurveyId;
        $aData['active'] = $oSurvey->active;
        $aData['sLanguage'] = App()->language;
        $aData['sFirstName'] = $aFieldValue['sFirstName'];
        $aData['sLastName'] = $aFieldValue['sLastName'];
        $aData['sEmail'] = $aFieldValue['sEmail'];
        $aData['aAttribute'] = $aFieldValue['aAttribute'];
        $aData['aExtraAttributes'] = $aRegisterAttributes;
        $aData['bCaptcha'] = isCaptchaEnabled('registrationscreen', $oSurvey->usecaptcha);
        $aData['sRegisterFormUrl'] = App()->createUrl('register/index', array('sid'=>$iSurveyId));

        $aData['formAdditions'] = '';
        if (!empty($registerFormEvent)) {
            $aData['formAdditions'] = $registerFormEvent['formAppend'];
        }

        if (is_array($this->aRegisterErrors)) {
            $aData['aErrors'] = $this->aRegisterErrors;
        } else {
            $aData['aErrors'] = array();
        }

        $aData['sStartDate'] = $this->getStartDate($iSurveyId, true);

        $aData['thissurvey'] = $oSurvey->attributes;

        return $aData;
    }

    /**
     * Send the register email with $_POST value
     * @param $iSurveyId Survey Id to register
     * @return boolean : if email is set to sent (before SMTP problem)
     */
    public function sendRegistrationEmail($iSurveyId, $iTokenId)
    {

        $sLanguage = App()->language;
        $aSurveyInfo = getSurveyInfo($iSurveyId, $sLanguage);

        $aMail = array();
        $aMail['subject'] = $aSurveyInfo['email_register_subj'];
        $aMail['message'] = $aSurveyInfo['email_register'];
        $aReplacementFields = array();
        $aReplacementFields["{ADMINNAME}"] = $aSurveyInfo['adminname'];
        $aReplacementFields["{ADMINEMAIL}"] = $aSurveyInfo['adminemail'];
        $aReplacementFields["{SURVEYNAME}"] = $aSurveyInfo['name'];
        $aReplacementFields["{SURVEYDESCRIPTION}"] = $aSurveyInfo['description'];
        $aReplacementFields["{EXPIRY}"] = $aSurveyInfo["expiry"];
        $oToken = Token::model($iSurveyId)->findByPk($iTokenId); // Reload the token (needed if just created)
        foreach ($oToken->attributes as $attribute=>$value) {
            $aReplacementFields["{".strtoupper($attribute)."}"] = $value;
        }
        $sToken = $oToken->token;
        $useHtmlEmail = (getEmailFormat($iSurveyId) == 'html');
        $aMail['subject'] = preg_replace("/{TOKEN:([A-Z0-9_]+)}/", "{"."$1"."}", $aMail['subject']);
        $aMail['message'] = preg_replace("/{TOKEN:([A-Z0-9_]+)}/", "{"."$1"."}", $aMail['message']);
        $aReplacementFields["{SURVEYURL}"] = Yii::app()->getController()->createAbsoluteUrl("/survey/index/sid/{$iSurveyId}", array('lang'=>$sLanguage, 'token'=>$sToken));
        $aReplacementFields["{OPTOUTURL}"] = Yii::app()->getController()->createAbsoluteUrl("/optout/tokens/surveyid/{$iSurveyId}", array('langcode'=>$sLanguage, 'token'=>$sToken));
        $aReplacementFields["{OPTINURL}"] = Yii::app()->getController()->createAbsoluteUrl("/optin/tokens/surveyid/{$iSurveyId}", array('langcode'=>$sLanguage, 'token'=>$sToken));
        foreach (array('OPTOUT', 'OPTIN', 'SURVEY') as $key) {
            $url = $aReplacementFields["{{$key}URL}"];
            if ($useHtmlEmail) {
                            $aReplacementFields["{{$key}URL}"] = "<a href='{$url}'>".htmlspecialchars($url).'</a>';
            }
            $aMail['subject'] = str_replace("@@{$key}URL@@", $url, $aMail['subject']);
            $aMail['message'] = str_replace("@@{$key}URL@@", $url, $aMail['message']);
        }
        // Replace the fields
        $aMail['subject'] = ReplaceFields($aMail['subject'], $aReplacementFields);
        $aMail['message'] = ReplaceFields($aMail['message'], $aReplacementFields);
        $sFrom = "{$aSurveyInfo['adminname']} <{$aSurveyInfo['adminemail']}>";
        $sBounce = getBounceEmail($iSurveyId);
        $sTo = $oToken->email;
        $sitename = Yii::app()->getConfig('sitename');
        // Plugin event for email handling (Same than admin token but with register type)
        $event = new PluginEvent('beforeTokenEmail');
        $event->set('survey', $iSurveyId);
        $event->set('type', 'register');
        $event->set('model', 'register');
        $event->set('subject', $aMail['subject']);
        $event->set('to', $sTo);
        $event->set('body', $aMail['message']);
        $event->set('from', $sFrom);
        $event->set('bounce', $sBounce);
        $event->set('token', $oToken->attributes);
        App()->getPluginManager()->dispatchEvent($event);
        $aMail['subject'] = $event->get('subject');
        $aMail['message'] = $event->get('body');
        $sTo = $event->get('to');
        $sFrom = $event->get('from');
        $sBounce = $event->get('bounce');

        $aRelevantAttachments = array();
        if (isset($aSurveyInfo['attachments'])) {
            $aAttachments = unserialize($aSurveyInfo['attachments']);
            if (!empty($aAttachments)) {
                if (isset($aAttachments['registration'])) {
                    LimeExpressionManager::singleton()->loadTokenInformation($aSurveyInfo['sid'], $sToken);

                    foreach ($aAttachments['registration'] as $aAttachment) {
                        if (LimeExpressionManager::singleton()->ProcessRelevance($aAttachment['relevance'])) {
                            $aRelevantAttachments[] = $aAttachment['url'];
                        }
                    }
                }
            }
        }

        if ($event->get('send', true) == false) {
            $this->sMessage = $event->get('message', $this->sMailMessage); // event can send is own message
            if ($event->get('error') == null) {
// mimic core system, set send to today
                $today = dateShift(date("Y-m-d H:i:s"), "Y-m-d H:i", Yii::app()->getConfig('timeadjust'));
                $oToken->sent = $today;
                $oToken->save();
            }
        } elseif (SendEmailMessage($aMail['message'], $aMail['subject'], $sTo, $sFrom, $sitename, $useHtmlEmail, $sBounce, $aRelevantAttachments)) {
            // TLR change to put date into sent
            $today = dateShift(date("Y-m-d H:i:s"), "Y-m-d H:i", Yii::app()->getConfig('timeadjust'));
            $oToken->sent = $today;
            $oToken->save();
            $aMessage = array();
            $aMessage['mail-thanks'] = gT("Thank you for registering to participate in this survey.");
            $aMessage['mail-message'] = $this->sMailMessage;
            $aMessage['mail-contact'] = sprintf(gT("Survey administrator %s (%s)"), $aSurveyInfo['adminname'], $aSurveyInfo['adminemail']);
            $this->sMessage = $this->renderPartial('/survey/system/message', array('aMessage'=>$aMessage), true);
        } else {
            $aMessage['mail-thanks'] = gT("Thank you for registering to participate in this survey.");
            $aMessage['mail-message-error'] = gT("You are registered but an error happened when trying to send the email - please contact the survey administrator.");
            $aMessage['mail-contact'] = sprintf(gT("Survey administrator %s (%s)"), $aSurveyInfo['adminname'], $aSurveyInfo['adminemail']);
            $this->sMessage = $this->renderPartial('/survey/system/message', array('aMessage'=>$aMessage), true);
        }
        // Allways return true : if we come here, we allways trye to send an email
        return true;
    }

    /**
     * Get the token id according to filled values
     * @param $iSurveyId
     * @return integer : the token id created
     */
    public function getTokenId($iSurveyId)
    {

        $sLanguage = App()->language;
        $aSurveyInfo = getSurveyInfo($iSurveyId, $sLanguage);

        $aFieldValue = $this->getFieldValue($iSurveyId);
        // Now construct the text returned
        $oToken = Token::model($iSurveyId)->findByAttributes(array(
            'email' => $aFieldValue['sEmail']
        ));
        if ($oToken) {
            if ($oToken->usesleft < 1 && $aSurveyInfo['alloweditaftercompletion'] != 'Y') {
                $this->aRegisterErrors[] = gT("The email address you have entered is already registered and the survey has been completed.");
            } elseif (strtolower(substr(trim($oToken->emailstatus), 0, 6)) === "optout") {
                // And global blacklisting ?
            {
                $this->aRegisterErrors[] = gT("This email address cannot be used because it was opted out of this survey.");
            }
            } elseif (!$oToken->emailstatus && $oToken->emailstatus != "OK") {
                $this->aRegisterErrors[] = gT("This email address is already registered but the email adress was bounced.");
            } else {
                $this->sMailMessage = gT("The address you have entered is already registered. An email has been sent to this address with a link that gives you access to the survey.");
                return $oToken->tid;
            }
        } else {
            // TODO : move xss filtering in model
            $oToken = Token::create($iSurveyId);
            $oToken->firstname = sanitize_xss_string($aFieldValue['sFirstName']);
            $oToken->lastname = sanitize_xss_string($aFieldValue['sLastName']);
            $oToken->email = $aFieldValue['sEmail'];
            $oToken->emailstatus = 'OK';
            $oToken->language = $sLanguage;
            $aFieldValue['aAttribute'] = array_map('sanitize_xss_string', $aFieldValue['aAttribute']);
            $oToken->setAttributes($aFieldValue['aAttribute']);
            if ($aSurveyInfo['startdate']) {
                $oToken->validfrom = $aSurveyInfo['startdate'];
            }
            if ($aSurveyInfo['expires']) {
                $oToken->validuntil = $aSurveyInfo['expires'];
            }
            $oToken->generateToken();
            $oToken->save();
            $this->sMailMessage = gT("An email has been sent to the address you provided with access details for this survey. Please follow the link in that email to proceed.");
            return $oToken->tid;
        }
    }
    /**
     * Get the array of fill value from the register form
     * @param $iSurveyId
     * @return array : if email is set to sent (before SMTP problem)
     */
    public function getFieldValue($iSurveyId)
    {
        //static $aFiledValue; ?
        $sLanguage = Yii::app()->language;
        $aSurveyInfo = getSurveyInfo($iSurveyId, $sLanguage);
        $aFieldValue = array();
        $aFieldValue['sFirstName'] = Yii::app()->request->getPost('register_firstname', '');
        $aFieldValue['sLastName'] = Yii::app()->request->getPost('register_lastname', '');
        $aFieldValue['sEmail'] = Yii::app()->request->getPost('register_email', '');
        $aRegisterAttributes = $aSurveyInfo['attributedescriptions'];
        $aFieldValue['aAttribute'] = array();
        foreach ($aRegisterAttributes as $key=>$aRegisterAttribute) {
            if ($aRegisterAttribute['show_register'] == 'Y') {
                $aFieldValue['aAttribute'][$key] = Yii::app()->request->getPost('register_'.$key, '');
            }
        }
        return $aFieldValue;
    }

    /**
     * Get the array of extra attribute with caption
     * @param $iSurveyId
     * @return array
     */
    public function getExtraAttributeInfo($iSurveyId)
    {
        $sLanguage = Yii::app()->language;
        $aSurveyInfo = getSurveyInfo($iSurveyId, $sLanguage);
        $aRegisterAttributes = $aSurveyInfo['attributedescriptions'];
        foreach ($aRegisterAttributes as $key=>$aRegisterAttribute) {
            if ($aRegisterAttribute['show_register'] != 'Y') {
                unset($aRegisterAttributes[$key]);
            } else {
                $aRegisterAttributes[$key]['caption'] = ($aSurveyInfo['attributecaptions'][$key] ? $aSurveyInfo['attributecaptions'][$key] : ($aRegisterAttribute['description'] ? $aRegisterAttribute['description'] : $key));
            }
        }
        return $aRegisterAttributes;
    }
    /**
     * Get the date if survey is future
     * @param integer $iSurveyId
     * @return null|string date
     */
    public function getStartDate($iSurveyId)
    {
        $aSurveyInfo = getSurveyInfo($iSurveyId, Yii::app()->language);
        if (empty($aSurveyInfo['startdate']) || dateShift(date("Y-m-d H:i:s"), "Y-m-d H:i:s", Yii::app()->getConfig("timeadjust")) >= $aSurveyInfo['startdate']) {
                    return;
        }
        Yii::app()->loadHelper("surveytranslator");
        $aDateFormat = getDateFormatData(getDateFormatForSID($iSurveyId, Yii::app()->language), Yii::app()->language);
        $datetimeobj = new Date_Time_Converter($aSurveyInfo['startdate'], 'Y-m-d H:i:s');
        return $datetimeobj->convert($aDateFormat['phpdate']);
    }
    /**
     * Display needed public page
     * @param $iSurveyId
     * @param string $registerContent
     */
    private function display($iSurveyId, $iTokenId = null, $registerContent)
    {
        $sLanguage = Yii::app()->language;
        $this->aGlobalData['surveyid'] = $surveyid = $iSurveyId;
        $this->aGlobalData['thissurvey'] = getSurveyInfo($iSurveyId, $sLanguage);
        Yii::app()->setConfig('surveyID', $iSurveyId); //Needed for languagechanger
        $this->aReplacementData['sitename'] = Yii::app()->getConfig('sitename');
        $this->aReplacementData['aRegisterErrors'] = $this->aRegisterErrors;
        $this->aReplacementData['sMessage'] = $this->sMessage;

        $oTemplate = Template::model()->getInstance('', $iSurveyId);
        $aSurveyInfo  =  getsurveyinfo($iSurveyId);

        if ($iTokenId !== null) {
            $aData['aSurveyInfo'] = self::getRegisterSuccess($iSurveyId, $iTokenId);
            $aData['registerSuccess'] = true;
        } else {
            $aData['aSurveyInfo'] = self::getRegisterForm($iSurveyId);
        }

        $aData['aSurveyInfo']['registration_view'] = $registerContent;

        $aData['aSurveyInfo']['registerform']['hiddeninputs'] = '<input value="'.$aData['aSurveyInfo']['sLanguage'].'"  type="hidden" name="lang" id="register_lang" />';
        $aData['aSurveyInfo']['include_content'] = 'register';

        $aData['aSurveyInfo'] = array_merge($aSurveyInfo, $aData['aSurveyInfo']);

        $aData['aSurveyInfo']['alanguageChanger']['show'] = false;
        $alanguageChangerDatas = getLanguageChangerDatas(App()->language);

        if ($alanguageChangerDatas) {
            $aData['aSurveyInfo']['alanguageChanger']['show']  = true;
            $aData['aSurveyInfo']['alanguageChanger']['datas'] = $alanguageChangerDatas;
        }
        Yii::app()->clientScript->registerScriptFile(Yii::app()->getConfig("generalscripts").'nojs.js', CClientScript::POS_HEAD);
        Yii::app()->twigRenderer->renderTemplateFromFile('layout_global.twig', $aData, false);

    }

}
