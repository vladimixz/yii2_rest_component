<?php

namespace vladimixz;

use app\models\User;
use Yii;
use yii\filters\auth\HttpBearerAuth;
use yii\base\ExitException;
use Facebook\Facebook;
use Facebook\Exceptions;
use Firebase\JWT\JWT;
use UnexpectedValueException;
use Abraham\TwitterOAuth\TwitterOAuth;


/**
 *
 * @property void $auth
 * @property void|string $identity
 */
class BearerAuth extends HttpBearerAuth
{

    public $realm = 'api';
    public $registerAction;
    public $loginAction;
    public $facebookCredentials;

    public $authData;
    public $currentAction;
    public $headerAuth = [
        'authorization' => 'authorization',
        'facebook-authorization' => 'facebookAuthorization',
        'twitter-authorization' => 'twitterAuthorization',
        'email-authorization' => 'emailAuthorization',
    ];

    /**
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
            $this->handleFailure('Your request was made with invalid credentials.');
        }

        return null;
    }

    /**
     * Get Auth gata
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
            $this->handleFailure('Please specify email and password for auth!');
        }
        return $this->$authFunction();
    }

    /**
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
     * @param null $param
     * @return mixed
     */
    public function request($param = null)
    {
        $method = Yii::$app->request->method == 'GET' ? 'get' : 'post';
        return Yii::$app->request->$method($param);
    }

    /**
     * @return User|array|null|\yii\db\ActiveRecord
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
     * @return User
     */
    public function createIdentity()
    {
        $identityClass = Yii::$app->user->identityClass;
        $identity = new $identityClass();
        $identity->setScenario($identityClass::SCENARIO_REGISTER);
        $identity->setAttributes($this->request());
        $identity->setAttributes($this->authData);
        $identity->setAttribute('token', $this->getJWT($identity->email));
        if (!isset($identity->password)) {
            $identity->setAttribute('password', Yii::$app->getSecurity()->generateRandomString());
        }
        if (!$identity->save()) {
            $this->handleFailure($identity->getErrors());
        }
        return $identity;
    }

    /**
     * @return array|null|\yii\db\ActiveRecord
     */
    public function findIdentity()
    {
        $identityClass = Yii::$app->user->identityClass;
        if (isset($this->authData['token'])) {
            $identity = $identityClass::find()->where(['token' => $this->authData['token']])->one();
            $decodedToken = $this->decodeToken($this->authData['token']);
            if (!$decodedToken){
                $this->handleFailure('Token was expired or it was corrupted!');
            } elseif ($decodedToken->user != $identity['email']) {
                $this->handleFailure('Invalid token!');
            }
        } else {
            $identity = $identityClass::find()->where(['email' => $this->authData['email']])->one();
            if ($identity) {
                if (isset($this->authData['password'])) {
                    $validatePassword = Yii::$app->getSecurity()->validatePassword(
                        $this->authData['password'], $identity->getAttribute('password')
                    );
                    if (!$validatePassword) {
                        $this->handleFailure('Invalid password!');
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
        }
        return $result;
    }

    /**
     * @return array
     */
    public function authorization()
    {
        if ($this->currentAction == $this->registerAction) {
            $this->handleFailure('You can`t register new user by token');
        }
        return ['token' => Yii::$app->request->getHeaders()->get('Authorization')];
    }

    /**
     * @return array
     */
    public function emailAuthorization()
    {
        $this->checkAuthOpportunity();
        return ['email' => $this->request('email'), 'password' => $this->request('password')];
    }

    /**
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
            $this->handleFailure($e->getMessage());
        } catch(Exceptions\FacebookSDKException $e) {
            $this->handleFailure($e->getMessage());
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
            $this->handleFailure('Please provide an access to your Facebook account!');
        }
        return $user;
    }

    /**
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
            $this->handleFailure('Unable to sign in with your twitter credentials');
        }
        $user = ['twitterId' => (string) $user['id'], 'email' => $user['email'], 'fullName' => $user['name']];
        return $user;
    }

    /**
     *
     */
    public function checkAuthOpportunity()
    {
        if ($this->currentAction != $this->registerAction && $this->currentAction != $this->loginAction) {
            $this->handleFailure('Invalid Auth credentials, use token!');
        }
    }


}
