<?php
/*
 * This file is part of the Virtual-Identity Twitter package.
 *
 * (c) Virtual-Identity <dev.saga@virtual-identity.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace VirtualIdentity\TwitterBundle\Services;

use Symfony\Component\Serializer\Normalizer\GetSetMethodNormalizer;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

use Doctrine\ORM\EntityManager;
use Monolog\Logger;

use VirtualIdentity\TwitterBundle\Entity\TwitterEntity;
use VirtualIdentity\TwitterBundle\Exceptions\ApiException;
use VirtualIdentity\TwitterBundle\EventDispatcher\TweetChangedEvent;

/**
 * TwitterService
 * =================
 *
 * The Twitter Service is one of many services used by the AggregatorBundle.
 * However the TwitterService can be used indepenendly of the Aggregator.
 * It eases iterating over your twitter api-call results.
 *
 * Probably the most important methods you want to use are:
 * * getFeed
 * * syncDatabase
 *
 * You might want to call the syncDatabase-method using a cron job. This call is then
 * forwarded to all other social media services.
 *
 * TODO: extend documentation
 */
class TwitterService
{
    /**
     * Logger used to log error and debug messages
     * @var Monolog\Logger
     */
    protected $logger;

    /**
     * Entity Manager used to persist and load TwitterEntities
     * @var Doctrine\ORM\EntityManager
     */
    protected $em;

    /**
     * The class name (fqcn) is used to persist twitter entities
     * @var String
     */
    protected $socialEntityClass;

    /**
     * If new entities should automatically be approved or not
     * @var boolean
     */
    protected $autoApprove;

    /**
     * The host used for communicating with the twitter api
     * @var String
     */
    protected $host;

    /**
     * The authentication credentials for connecting to the twitter api
     * @var array
     */
    protected $authentication;

    /**
     * The QueryBuilder used to query the twitter entities
     * @var Doctrine\ORM\QueryBuilder
     */
    protected $qb;

    /**
     * The API-Requests that are used to retrieve the tweets
     * @var array
     */
    protected $apiRequests;

    /**
     * The tmhOAuth Api
     * @var \tmhOAuth
     */
    protected $api;

    /**
     * an event dispatcher that is used to dispatch certain events, like when the approval status is changed
     * @var [type]
     */
    protected $dispatcher;

    /**
     * Creates a new Aggregator Service. The most important methods are the getFeed and syncDatabase methods.
     *
     * @param Logger        $logger debug messages are logged here
     * @param EntityManager $em     persistence manager
     */
    public function __construct(Logger $logger, EntityManager $em, EventDispatcherInterface $dispatcher)
    {
        $this->dispatcher = $dispatcher;
        $this->logger = $logger;
        $this->em = $em;
    }

    /**
     * Sets which class is used to persist the twitter entities.
     *
     * @param String $socialEntityClass Use fqcn (Full qualified class name) here.
     */
    public function setSocialEntityClass($socialEntityClass)
    {
        $this->socialEntityClass = $socialEntityClass;
        $this->initializeQueryBuilder();
    }

    /**
     * Sets if new entities should be approved automatically. If set to true, new
     * posts/tweets/etc will automatically appear in the feed. If set to false, you have
     * to approve them manually using the admin-interface reachable via /hydra/moderate
     *
     * @param boolean $autoApprove if entities should be autoapproved or not
     */
    public function setAutoApprove($autoApprove)
    {
        $this->autoApprove = $autoApprove;
    }

    /**
     * Sets the host that is used in the API-Requests
     *
     *  @param String $host API-Host
     */
    public function setHost($host)
    {
        $this->host = $host;
    }

    /**
     * Sets the api request used to retrieve the tweets.
     * At the moment only GET-requests are allowed. For
     * example 1.1/statuses/user_timeline.json
     *
     * @param String $url the api request
     */
    public function setApiRequests(array $urls)
    {
        $this->apiRequests = $urls;
    }

    /**
     * Sets the authentication parameters used to connect to the api.
     * You can obtain those values from your app-oauth page.
     * The URL for this page is like following: https://dev.twitter.com/apps/{appId}/oauth
     *
     * @param String $consumerKey      The twitter consumer key
     * @param String $consumerSecret   The twitter consumer secret
     * @param String $oauthToken       The twitter access token
     * @param String $oauthTokenSecret The twitter access token secret
     */
    public function setAuthentication($consumerKey, $consumerSecret, $oauthToken, $oauthTokenSecret)
    {
        $this->authentication = array(
            'consumer_key' => $consumerKey,
            'consumer_secret' => $consumerSecret
        );

        if (!empty($oauthToken)) {
            $this->authentication['token'] = $oauthToken;
        }

        if (!empty($oauthTokenSecret)) {
            $this->authentication['secret'] = $oauthTokenSecret;
        }

        $this->initializeApi();
    }

    /**
     * Sets the approval-status of one tweet. Furthermore it dispatches an event.
     *
     * @param int  $tweetId  the tweet id
     * @param bool $approved whether or not the tweet is approved
     * @return bool
     */
    public function setApproved($tweetId, $approved)
    {
        $repository = $this->em->getRepository($this->socialEntityClass);

        $tweet = $repository->findOneById($tweetId);

        if ($tweet == null) {
            throw new \InvalidArgumentException('The tweet with ID '.$tweetId.' could not be found!');
        }

        $tweet->setApproved($approved);

        $this->em->persist($tweet);
        $this->em->flush();

        $this->dispatcher->dispatch(
            'virtual_identity_twitter.post_approval_change',
            new TweetChangedEvent($tweet)
        );

        return $approved;
    }

    /**
     * Returns the query builder used to query the database where the twitter entities are stored.
     * You can change anything you want on it before calling the getFeed-method.
     *
     * @return Doctrine\ORM\QueryBuilder
     */
    public function getQueryBuilder()
    {
        return $this->qb;
    }

    /**
     * Returns the whole aggregated feed. You can limit the list giving any number.
     *
     * @param bool $onlyApproved if only approved elements should be returned. default is true.
     * @param int  $limit        how many items should be fetched at maximum
     * @return array<TwitterEntityInterface> List of twitter entities
     */
    public function getFeed($onlyApproved = true, $limit = false)
    {
        if ($limit !== false && is_int($limit)) {
            $this->qb->setMaxResults($limit);
        }
        if ($onlyApproved) {
            $this->qb->andWhere('e.approved = true');
        }
        return $this->qb->getQuery()->getResult();
    }

    /**
     * Syncs the database of twitter entities with the entities of each social channel configured
     * to be looked up by the aggregator
     *
     * @return void
     */
    public function syncDatabase()
    {
        if (!$this->api) {
            throw new ApiException('Api not initialized! Use setAuthentication to implicitly initialize the api.');
        }

        foreach ($this->apiRequests as $url) {
            $params = array();
            $query = parse_url($url, PHP_URL_QUERY);
            parse_str($query, $params);

            $status = $this->api->request(
                'GET',
                $this->api->url($url),
                $params
            );

            if ($status == 200) {

                $response = json_decode($this->api->response['response'], true);
                if (isset($response['statuses'])) {
                    $response = $response['statuses'];
                }
                $repository = $this->em->getRepository($this->socialEntityClass);

                foreach ($response as $rawTweet) {
                    if (!isset($rawTweet['id_str'])) {
                        throw new ApiException('Tweet could not be recognized! There was no id_str in the entity: '.print_r($rawTweet, 1));
                    }
                    if (!count($repository->findOneByIdStr($rawTweet['id_str']))) {
                        $twitterEntity = $this->deserializeRawObject($rawTweet, array('created_at'));

                        $twitterEntity->setRaw(json_encode($rawTweet));
                        $twitterEntity->setApproved($this->autoApprove);

                        $this->em->persist($twitterEntity);
                    } else {
                        continue;
                    }
                }
                $this->em->flush();
            } else {
                throw new ApiException('Request was unsuccessful! Status code: '.$status.'. Response was: '.$this->api->response['response']);
            }
        }
    }

    /**
     * Gives access to the tmhOauth instance for specialised use of tha twitter api.
     * There already exists a method for gaining an authorization-url.
     *
     * @return \tmhOauth
     */
    public function getApi()
    {
        return $this->api;
    }

    /**
     * Calls the twitter api to create a request token and then generates the
     * authorization url. You should store the returned token and secret in the session.
     * When the user must is redirected to the given callback url you can then
     * obtain the access token with those session-parameters.
     *
     * @param  string $callBackUrl the url where the user should be redirected after authorizing the app
     * @return array               the keys of the return are url, userSessionToken and userSessionSecret
     */
    public function getAuthorizationParameters($callBackUrl)
    {
        // send request for a request token
        $status = $this->api->request('POST', $this->api->url('oauth/request_token', ''), array(
            // pass a variable to set the callback
            'oauth_callback' => $callBackUrl
        ));

        if ($status == 200) {

            // get and store the request token
            $response = $this->api->extract_params($this->api->response['response']);

            // generate redirection url the user needs to be redirected to
            $return = array(
                'url' => $this->api->url('oauth/authorize', '') . '?oauth_token=' . $response['oauth_token'],
                'userSessionToken' => $response['oauth_token'],
                'userSessionSecret' => $response['oauth_token_secret'],
            );

            return $return;
        }

        throw new ApiException('Obtaining an request token did not work! Status code: '.$status.'. Response was: '.$this->api->response['response']);
    }

    /**
     * Returns an array with the permanent access token and access secret
     *
     * @param  string $userSessionToken  the current users session token
     * @param  string $userSessionSecret the current users session secret
     * @param  string $oauthVerifier     verifier that was sent to the callback url
     * @return array                     the keys of the returned array are accessToken and accessTokenSecret
     */
    public function getAccessToken($userSessionToken, $userSessionSecret, $oauthVerifier)
    {
        // set the request token and secret we have stored
        $this->api->config['user_token'] = $userSessionToken;
        $this->api->config['user_secret'] = $userSessionSecret;

        // send request for an access token
        $status = $this->api->request('POST', $this->api->url('oauth/access_token', ''), array(
            // pass the oauth_verifier received from Twitter
            'oauth_verifier' => $oauthVerifier
        ));

        if ($status == 200) {

            // get the access token and store it in a cookie
            $response = $this->api->extract_params($this->api->response['response']);

            $return = array(
                'accessToken' => $response['oauth_token'],
                'accessTokenSecret' => $response['oauth_token_secret'],
            );

            return $return;
        }

        throw new ApiException('Obtaining the acecss token did not work! Status code: '.$status.'. Last error message was: '.$this->api->response['error']);
    }

    /**
     * Checks if the authentication credentials currently stored in hydra.yml are correct or not.
     *
     * @return boolean
     */
    public function isAccessTokenValid()
    {
        $this->api->request('GET', $this->api->url('1.1/account/verify_credentials'));

        // HTTP 200 means we were successful
        return ($this->api->response['code'] == 200);
    }

    /**
     * Flattens an multidimensional array to a one dimensional one.
     * Keys are preserved in nesting order. The keys are then glued
     * by the second parameter $glue.
     *
     * @param  array  $array The array that should be flattened
     * @param  String $glue  The string that glues the keys together
     * @return array
     */
    public static function flatten($array, $glue = '_') {
        $result = array();
        $it = new \RecursiveIteratorIterator(new \RecursiveArrayIterator($array));
        foreach ($it as $v) {
            $d = $it->getDepth();
            $breadcrumb = array();
            for ($cd = 0; $cd <= $d; $cd ++) $breadcrumb[] = $it->getSubIterator($cd)->key();

            $result[join($breadcrumb, $glue)] = $v;
        }
        return $result;
    }

    /**
     * Is called by the constructor. Creates an initializes the query builder.
     * The Entity is set, default ordering by date descending is set
     */
    protected function initializeQueryBuilder()
    {
        $this->qb = $this->em->createQueryBuilder();
        $this->qb
                ->select('e')
                ->from($this->socialEntityClass, 'e')
                ->orderBy('e.createdAt', 'DESC');
    }

    /**
     * The raw response from the api must be mapped to a correctly typed object.
     * This method does the job flattening the result and by using a GetSetMethodNormalizer.
     * What does that mean? This means that if your response has a key $entry['id_str'] the
     * setter setIdStr($entry['id_str']) is used. If the response has a key
     * $entry['media'][0]['media_url'] the setter setMedia0MediaUrl(..) is used. Therefore
     * you can persist whatever information you want to persist from the direct response
     * by using the correct name for the entity-field (and then also for the setter).
     *
     * @param  object $object     the json decoded object response by the api
     * @param  array  $dateFields fields that should be formatted as datetime object
     * @return TwitterEntityInterface
     */
    protected function deserializeRawObject($object, $dateFields = array())
    {
        // flatten object
        $object = self::flatten($object);
        foreach ($dateFields as $df) {
            if (array_key_exists($df, $object)) {
                $object[$df] = new \DateTime($object[$df]);
            }
        }
        $normalizer = new GetSetMethodNormalizer();
        $normalizer->setCamelizedAttributes(array_keys($object));
        return $normalizer->denormalize($object, $this->socialEntityClass);
    }

    /**
     * creates and initializes the api with the host and the authentication parameters
     */
    protected function initializeApi()
    {
        $ops = $this->authentication;
        $ops['host'] = $this->host;

        $this->api = new \tmhOAuth($ops);
    }
}