<?php
/**
 * Send a curl post request after each afterSurveyComplete event
 *
 * @author Nicola Manica <nicola.manica@gmail.com>
 * @copyright 2016
 * @license GPL v3
 * @version 1.0.0
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 */
    class HttpHook extends \ls\pluginmanager\PluginBase {

        protected $storage = 'DbStorage';
        static protected $description = 'Webhook for Limesurvey: send a curl POST after every response submission.';
        static protected $name = 'HttpHook';

        public function init() {
            $this->subscribe('afterSurveyComplete');
            $this->subscribe('beforeSurveySettings');
            $this->subscribe('newSurveySettings');
        }


	protected $settings = array(
            'bUse' => array(
            'type' => 'select',
            'options'=>array(
            0=>'No',
            1=>'Yes'
            ),
            'default'=>1,
            'label' => 'Send a hook for every survey by default?',
            'help'=>'Overwritable in each Survey setting',
            ),
            'sUrl' => array(
            'type' => 'string',
            'default'=>'http://<address>',
            'label' => 'The default address to send the webhook to',
            'help'=>'Webservices to get notified event',
            ),
            'bDebugMode' => array(
            'type' => 'select',
            'options'=>array(
            0=>'No',
            1=>'Yes'
            ),
            'default'=>0,
            'label' => 'Enable Debug Mode',
            'help'=>'Enable debugmode to see what data is transmitted',
        	  )

	);

  /**
    * Add setting on survey level: send hook only for certain surveys / url setting per survey / auth code per survey / send user token / send question response
    */
    public function beforeSurveySettings()
    {
      $oEvent = $this->event;
      traceVar($oEvent); 
      $oEvent->set("surveysettings.{$this->id}", array(
        'name' => get_class($this),
        'settings' => array(
        'bUse' => array(
          'type' => 'select',
          'label' => 'Send a hook for this survey',
          'options'=>array(
            0=> 'No',
            1=> 'Yes',
            2=> 'Use site settings (default)'
          ),
          'default'=>2,
          'help'=>'Leave default to use global setting',
          'current'=> $this->get('bUse','Survey',$oEvent->get('survey')),
        ),
        'bUrlOverwrite' => array(
          'type' => 'select',
          'label' => 'Overwrite the global Hook Url',
          'options'=>array(
            0=> 'No',
            1=> 'Yes'
          ),
          'default'=>0,
          'help'=>'Set to Yes if you want to use a specific URL for this survey',
          'current'=> $this->get('bUrlOverwrite','Survey',$oEvent->get('survey')),
        ),
        'sUrl' => array(
        'type' => 'string',
        'label' => 'The  address to send the hook for this survey to:',
        'help'=>'Leave blank to use global setting',
        'current'=> $this->get('sUrl','Survey',$oEvent->get('survey')),
       ),
      ),
    ));
  }

    /**
      * Save the settings
      */
      public function newSurveySettings()
    {
        $event = $this->event;
        traceVar($event);
        foreach ($event->get('settings') as $name => $value)
        {
            /* In order use survey setting, if not set, use global, if not set use default */
            $default=$event->get($name,null,null,isset($this->settings[$name]['default'])?$this->settings[$name]['default']:NULL);
            $this->set($name, $value, 'Survey', $event->get('survey'),$default);
        }
    }


        /**
        * Send the webhook on completion of a survey
        * @return array | response
        */
        public function afterSurveyComplete()
        {  
          $time_start = microtime(true);
          $oEvent     = $this->getEvent();
          $sSurveyId = $oEvent->get('surveyId');
          traceVar($this->isHookEnabled($sSurveyId));
          if(!$this->isHookEnabled($sSurveyId))
          {
              return;
          }
          $url = ($this->get('bUrlOverwrite','Survey',$sSurveyId)==='1') ? $this->get('sUrl','Survey',$sSurveyId) : $this->get('sUrl',null,null,$this->settings['sUrl']);
          traceVar($url);
          $additionalFields = $this->getAdditionalFields($sSurveyId);

          $responseId = $oEvent->get('responseId');
          if(($this->get('bSendToken','Survey',$sSurveyId)==='1')||(count($additionalFields) > 0))
          {
              //$response = $this->api->getResponse($sSurveyId, $responseId);
              $sToken = $this->getTokenString($sSurveyId,$response);
              $additionalAnswers = $this->getAdditionalAnswers($additionalFields, $response);  
          }
          
          $parameters = array(
              "survey" => $sSurveyId,
              "token" => (isset($sToken)) ? $sToken : null,
              "responseId" => (isset($responseId)) ? $responseId : null     
              "additionalFields" => ($additionalFields) ? json_encode($additionalAnswers) : null
          );
          
          $hookSent = $this->httpPost($url,$parameters);
          $this->debug($parameters, $hookSent, $time_start);
          
          return;
        }

          
        /**
        *   httpPost function http://hayageek.com/php-curl-post-get/
        *   creates and executes a POST request
        *   returns the output 
        */
        private function httpPost($url,$params)
        {
          $postData = json_encode($params);
          $fp = fopen('/tmp/errorlog.txt', 'w');
          $ch = curl_init();
          curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
          curl_setopt($ch,CURLOPT_URL,$url);
          curl_setopt($ch,CURLOPT_RETURNTRANSFER,true);
          curl_setopt($ch,CURLOPT_HEADER, false);
          curl_setopt($ch, CURLOPT_POST, count($postData));
          curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
          $output=curl_exec($ch);
          curl_close($ch);
          return $output;
          }

          /**
          *   check if the hook should be sent
          *   returns a boolean
          */
          
          private function isHookEnabled($sSurveyId)
          {
            return ($this->get('bUse','Survey',$sSurveyId)==0)||(($this->get('bUse','Survey',$sSurveyId)==2) && ($this->get('bUse',null,null,$this->settings['bUse'])==0));
          }

          
          /**
          *   check if the hook should be sent
          *   returns a boolean
          */
          private function getTokenString($sSurveyId,$response)
          {
            return ($this->get('bSendToken','Survey',$sSurveyId)==='1') ? $response['token'] : null;
          }

          /**
          *
          *
          */
          private function getAdditionalFields($sSurveyId)
          {
            $additionalFieldsString = $this->get('sAnswersToSend','Survey',$sSurveyId);
            if($additionalFieldsString != ''||$additionalFieldsString != null)
            {
            return explode(',',$this->get('sAnswersToSend','Survey',$sSurveyId));
            }
            return null;
          }
          
          private function getAdditionalAnswers($additionalFields=null, $response)
          {
            if($additionalFields)
            {
              $additionalAnswers = array();
              foreach($additionalFields as $field)
              {
                $additionalAnswers[$field] = htmlspecialchars($response[$field]);
              }
            return $additionalAnswers;
            }
          return null;
          }

          private function debug($parameters, $hookSent, $time_start)
          {
            if($this->get('bDebugMode',null,null,$this->settings['bDebugMode'])==1)
              {
                echo '<pre>';
                var_dump($parameters);
                echo "<br><br> ----------------------------- <br><br>";
                var_dump($hookSent);
                echo "<br><br> ----------------------------- <br><br>";
                echo 'Total execution time in seconds: ' . (microtime(true) - $time_start);
                echo '</pre>';
              }
          }

          

          
}
