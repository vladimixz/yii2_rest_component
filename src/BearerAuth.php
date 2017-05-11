<?php

namespace vladimixz;

use Yii;
use yii\filters\auth\HttpBearerAuth;
use yii\base\ExitException;
use Facebook\Facebook;
use Facebook\Exceptions;
use Firebase\JWT\JWT;
use UnexpectedValueException;
use Abraham\TwitterOAuth\TwitterOAuth;
use DomainException;


/**
 * Component for authorization and authentication users via facebook, twitter, email/password
 * Based on JWT component
 * @property void $auth
 * @property void|string $identity
 */
class BearerAuth extends HttpBearerAuth
{

    public $registerAction;
    public $loginAction;

    public $authData;
    public $currentAction;
    public $headerAuth = [
        'authorization' => 'authorization',
        'facebook-authorization' => 'facebookAuthorization',
        'twitter-authorization' => 'twitterAuthorization',
        'email-authorization' => 'emailAuthorization',
    ];

    /**
     * Authenticate user, method called in controller before action
     * @inheritdoc
     */
    public function authenticate($user, $request, $response)
    {
        $this->currentAction = Yii::$app->controller->action->id;
        $this->authData = $this->getAuthData();
        $identity = $this->getIdentity();
        if ($identity !== null && Yii::$app->user->login($identity)) {
            return Yii::$app->user->identity;
        } else {
            $this->handleFailure('Your request was made with invalid credentials.', 401);
        }

        return null;
    }

    /**
     * Get Auth data
     * @return bool|mixed|string
     */
    public function getAuthData()
    {
        $headersOverall = array_intersect(
            array_keys(Yii::$app->request->headers->toArray()),
            array_keys($this->headerAuth)
        );
        $authFunction = false;
        if (count($headersOverall) === 1) {
            $authFunction = $this->headerAuth[array_shift($headersOverall)];
        } elseif (count($headersOverall) === 0 && $this->request('email') && $this->request('password')) {
            $authFunction = $this->headerAuth['email-authorization'];
        } else {
            $this->handleFailure('Please specify email and password for auth!', 400);
        }
        return $this->$authFunction();
    }

    /**
     * Return error with status code
     * @inheritdoc
     */
    public function handleFailure($message, $code = null)
    {
        if ($code) {
            Yii::$app->response->statusCode = $code;
        }
        throw new ExitException(json_encode(['error' => $message]));
    }

    /**
     * Return request data of GET or POST
     * @param null $param
     * @return mixed
     */
    public function request($param = null)
    {
        $method = Yii::$app->request->method == 'GET' ? 'get' : 'post';
        return Yii::$app->request->$method($param);
    }

    /**
     * Create new (if action is register) or find exist identity
     * @return User|array|null|\yii\db\ActiveRecord|\yii\web\IdentityInterface
     */
    public function getIdentity()
    {
        $identity = null;
        if ($this->currentAction == $this->registerAction) {
            $identity = $this->createIdentity();
        } else {
            $identity = $this->findIdentity();
        }
        return $identity;
    }

    /**
     * Create new identity, only for register action
     * @return \yii\db\ActiveRecord User
     */
    public function createIdentity()
    {
        $identityClass = Yii::$app->user->identityClass;
        /* @var $identity \yii\db\ActiveRecord */
        $identity = new $identityClass();
        $identity->setAttributes($this->request());
        $identity->setAttributes($this->authData);
        $identity->setAttribute('token', $this->getJWT($identity->getAttribute('email')));
        if (!isset($identity->password)) {
            $identity->setAttribute('password', Yii::$app->getSecurity()->generateRandomString());
        }
        if (!$identity->save()) {
            $this->handleFailure($identity->getErrors(), 400);
        }
        return $identity;
    }

    /**
     * Find exist identity
     * @return array|null|\yii\db\ActiveRecord
     */
    public function findIdentity()
    {
        $identityFind = call_user_func([Yii::$app->user->identityClass, 'find']);
        /* @var $identity \yii\db\ActiveRecord */
        if (isset($this->authData['token'])) {
            $identity = $identityFind->where(['token' => $this->authData['token']])->one();
            $decodedToken = $this->decodeToken($this->authData['token']);
            if (!$decodedToken){
                $this->handleFailure('Token was expired or it was corrupted!', 401);
            } elseif ($decodedToken->user != $identity['email']) {
                $this->handleFailure('Invalid token!', 401);
            }
        } else {
            $identity = $identityFind->where(['email' => $this->authData['email']])->one();
            if ($identity) {
                if (isset($this->authData['password'])) {
                    $validatePassword = Yii::$app->getSecurity()->validatePassword(
                        $this->authData['password'], $identity->getAttribute('password')
                    );
                    if (!$validatePassword) {
                        $this->handleFailure('Invalid password!', 401);
                    }
                }
                $decodedToken = $this->decodeToken($identity->getAttribute('token'));
                if (!$decodedToken) {
                    $identity->setAttribute('token', $this->getJWT($identity->getAttribute('email')));
                    $identity->save();
                }
            }
        }
        return $identity;
    }

    /**
     * Create JWT token for identity
     * @param $email
     * @return string
     */
    public function getJWT($email)
    {
        $params = ['user' => $email];
        if (isset(Yii::$app->params['apiAuthCredentials']['jwtExp'])) {
            $params['exp'] = time() + Yii::$app->params['apiAuthCredentials']['jwtExp'];
        }
        return JWT::encode($params, Yii::$app->params['salt']);
    }

    /**
     * Decode token
     * @param $token
     * @return bool|object
     */
    public function decodeToken($token)
    {
        try {
            $decoded = JWT::decode($token, Yii::$app->params['salt'], ['HS256']);
            $result = $decoded;
        } catch (UnexpectedValueException $e) {
            $result = false;
        } catch (DomainException $e) {
            $result = false;
        }
        return $result;
    }

    /**
     * Auth function for authorization via JWT token
     * @return array
     */
    public function authorization()
    {
        if ($this->currentAction == $this->registerAction) {
            $this->handleFailure('You can`t register new user by token', 400);
        }
        return ['token' => Yii::$app->request->getHeaders()->get('Authorization')];
    }

    /**
     * Auth function for authorization or registration via email/password token
     * @return array
     */
    public function emailAuthorization()
    {
        $this->checkAuthOpportunity();
        return ['email' => $this->request('email'), 'password' => $this->request('password')];
    }

    /**
     * Auth function for authorization or registration via facebook
     * @return array
     */
    public function facebookAuthorization()
    {
        $this->checkAuthOpportunity();
        $fb = new Facebook(Yii::$app->params['apiAuthCredentials']['facebook']);
        $response = [];
        try {
            $response = $fb->get(
                '/me?fields=id,name,birthday,email',
                Yii::$app->request->getHeaders()->get('facebook-authorization')
            );
        } catch(Exceptions\FacebookResponseException $e) {
            $this->handleFailure($e->getMessage(), 400);
        } catch(Exceptions\FacebookSDKException $e) {
            $this->handleFailure($e->getMessage(), 400);
        }
        $user = $response->getGraphUser()->asArray();
        if ($response->getGraphUser()->getBirthday()) {
            $user['birthday'] = $response->getGraphUser()->getBirthday()->format('Y-m-d');
        }
        $user['fullName'] = $user['name'];
        $user['facebookId'] = $user['id'];
        unset($user['id']);
        unset($user['name']);
        if (!isset($user['email'])) {
            $this->handleFailure('Please provide an access to your Facebook account!', 401);
        }
        return $user;
    }

    /**
     * Auth function for authorization or registration via twitter
     * @return array
     */
    public function twitterAuthorization()
    {
        $this->checkAuthOpportunity();
        $config = Yii::$app->params['apiAuthCredentials']['twitter'];
        $connection = new TwitterOAuth(
            $config['consumerKey'],
            $config['consumerSecret'],
            Yii::$app->request->getHeaders()->get('Twitter-Authorization'),
            Yii::$app->request->getHeaders()->get('Twitter-Token-Secret')
        );
        $user = (array) $connection->get(
            "account/verify_credentials",
            ['include_email' => 'true', 'include_entities' => 'true']
        );
        if(isset($user['errors'])) {
            Yii::info('Twitter error: ' . json_encode($user['errors']));
        }
        if (!isset($user['email'])) {
            Yii::info('Twitter email not found in response!');
            $this->handleFailure('Unable to sign in with your twitter credentials', 401);
        }
        $user = ['twitterId' => (string) $user['id'], 'email' => $user['email'], 'fullName' => $user['name']];
        return $user;
    }

    /**
     * Check opportunity login or register with given credentials
     */
    public function checkAuthOpportunity()
    {
        if ($this->currentAction != $this->registerAction && $this->currentAction != $this->loginAction) {
            $this->handleFailure('Invalid Auth credentials, use token!', 400);
        }
    }


}
