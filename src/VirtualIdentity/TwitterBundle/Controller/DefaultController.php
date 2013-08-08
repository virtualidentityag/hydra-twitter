<?php
/*
 * This file is part of the Virtual-Identity Twitter package.
 *
 * (c) Virtual-Identity <dev.saga@virtual-identity.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace VirtualIdentity\TwitterBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\Yaml\Yaml;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;

use VirtualIdentity\TwitterBundle\Form\ConfigurationEntity;
use VirtualIdentity\TwitterBundle\Form\Type\ConfigurationEntityType;

class DefaultController extends Controller
{

    /**
     * @Route("/hydra/twitter/authorize", name="virtual_identity_twitter_authorize")
     */
    public function authorizeAction()
    {
        $service = $this->get('virtual_identity_twitter');

        $parameters = $service->getAuthorizationParameters(
            $this->generateUrl('virtual_identity_twitter_callbackurl', array(), true)
        );

        $this->get('session')->set('virtual_identity_twitter_user_token', $parameters['userSessionToken']);
        $this->get('session')->set('virtual_identity_twitter_user_secret', $parameters['userSessionSecret']);

        return new RedirectResponse($parameters['url']);
    }

    /**
     * @Route("/hydra/twitter/callbackurl", name="virtual_identity_twitter_callbackurl")
     * @Template()
     */
    public function callbackAction()
    {
        $service = $this->get('virtual_identity_twitter');

        $oauthToken = $this->getRequest()->query->get('oauth_token');
        $oauthVerifier = $this->getRequest()->query->get('oauth_verifier');

        $userSessionToken = $this->get('session')->get('virtual_identity_twitter_user_token');
        $userSessionSecret = $this->get('session')->get('virtual_identity_twitter_user_secret');

        return $service->getAccessToken($userSessionToken, $userSessionSecret, $oauthVerifier);
    }

    /**
     * @Route("/hydra/twitter/moderate/{tweetId}/{approved}", name="virtual_identity_twitter_moderate")
     * @Template()
     */
    public function moderateAction($tweetId = null, $approved = null)
    {
        $service = $this->get('virtual_identity_twitter');

        if ($tweetId !== null && $approved !== null && is_numeric($tweetId) && ($approved == '1' || $approved == '0')) {
            $service->setApproved($tweetId, (bool)$approved);
        }

        $paginator  = $this->get('knp_paginator');
        $pagination = $paginator->paginate(
            $service->getQueryBuilder(),
            $this->get('request')->query->get('page', 1), /*page number*/
            20 /*limit per page*/
        );

        return array(
            'credentialsValid' => $service->isAccessTokenValid(),
            'feed' => $pagination
        );
    }

    /**
     * @Route("/hydra/twitter/sync", name="virtual_identity_twitter_sync")
     * @Template()
     */
    public function syncAction()
    {
        $service = $this->get('virtual_identity_twitter');

        $service->syncDatabase();

        return array(
            'credentialsValid' => $service->isAccessTokenValid()
        );
    }

    /**
     * @Route("/hydra/twitter", name="virtual_identity_twitter_configure")
     * @Template()
     */
    public function configureAction()
    {
        $service = $this->get('virtual_identity_twitter');

        $configurationEntity = new ConfigurationEntity();
        $configurationEntity->setApiRequests($this->container->getParameter('virtual_identity_twitter.api_requests'));
        $configurationEntity->setConsumerKey($this->container->getParameter('virtual_identity_twitter.consumer_key'));
        $configurationEntity->setConsumerSecret($this->container->getParameter('virtual_identity_twitter.consumer_secret'));
        $configurationEntity->setToken($this->container->getParameter('virtual_identity_twitter.token'));
        $configurationEntity->setSecret($this->container->getParameter('virtual_identity_twitter.secret'));

        $form = $this->createForm(new ConfigurationEntityType(), $configurationEntity);

        $form->handleRequest($this->getRequest());

        if ($form->isValid()) {
            $hydraConfigFile = $this->get('kernel')->getRootDir().'/config/hydra.yml';

            if (file_exists($hydraConfigFile)) {
                $hydraConfig = Yaml::parse(file_get_contents($hydraConfigFile));
            } else {
                $hydraConfig = array();
            }

            $hydraConfig['virtual_identity_twitter']['api_requests']    = $configurationEntity->getApiRequests();
            $hydraConfig['virtual_identity_twitter']['consumer_key']    = $configurationEntity->getConsumerKey();
            $hydraConfig['virtual_identity_twitter']['consumer_secret'] = $configurationEntity->getConsumerSecret();
            $hydraConfig['virtual_identity_twitter']['token']           = $configurationEntity->getToken();
            $hydraConfig['virtual_identity_twitter']['secret']          = $configurationEntity->getSecret();

            // update runtime configuration
            $service->setAuthentication(
                $configurationEntity->getConsumerKey(),
                $configurationEntity->getConsumerSecret(),
                $configurationEntity->getToken(),
                $configurationEntity->getSecret()
            );
            $service->setApiRequests($configurationEntity->getApiRequests());

            // save changes
            file_put_contents($hydraConfigFile, Yaml::dump($hydraConfig, 3));

            // clear cache
            $application = new \Symfony\Bundle\FrameworkBundle\Console\Application($this->get('kernel'));
            $application->setAutoExit(false);
            $options = array('command' => 'cache:clear');
            $application->run(new \Symfony\Component\Console\Input\ArrayInput($options));
        }

        return array(
            'credentialsValid' => $service->isAccessTokenValid(),
            'form' => $form->createView()
        );
    }
}
