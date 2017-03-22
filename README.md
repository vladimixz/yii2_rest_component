# yii2_rest_component
yii2 rest component

Add this to params.php config
````
'salt' => '',
'apiAuthCredentials' => [
    'facebook' => [
        'default_graph_version' => 'v2.8',
        'app_id' => '',
        'app_secret' => '',
    ],
    'twitter' => [
        'consumerKey' => '',
        'consumerSecret' => '',
    ],
    'jwtExp' => 60*60*24*30 //month
],
````

In controller you can use component in behaviour as authenticator
````
/**
 * @inheritdoc
 */
public function behaviors () {
    return [
        'authenticator' => [
            'registerAction' => 'register',
            'loginAction' => 'login',
            'class' => BearerAuth::className(),
        ]
    ];
}
````