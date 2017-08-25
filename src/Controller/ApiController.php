<?php

namespace App\Controller;

use App\Controller\AppController;
use App\TokenGenerator;
use Cake\Event\Event;
use Cake\Http\Client;
use Cake\ORM\TableRegistry;
use Cake\Utility\Security;
use App\Model;

class ApiController extends AppController
{

    const RETURN_CODE_ERROR_AUTH = -3;
    const RETURN_CODE_ERROR_MISSING_PARAMETER = -2;
    const RETURN_CODE_ERROR_RETOUR_API = -1;
    const RETURN_CODE_SUCCESS = 0;


    const API_KEY_WEATHER = "6deb28875db5aae777450b29bda0f889";
    const API_KEY_DRINKS = "328da11a6e5144929f6bf83e1dc9e5da";
    const API_KEY_FOOD = "1db14a055d0691b833f56085dfd7eb57";

    private $data = array('return_code' => self::RETURN_CODE_SUCCESS, 'error' => "", 'returns' => array());

    public function initialize()
    {
        $this->loadComponent('TokenGenerator');
        parent::initialize(); // TODO: Change the autogenerated stub
    }

    public function beforeFilter(Event $event)
    {
        header('Content-Type: application/json');
        $this->autoRender = false;
        $this->layoutAjax = true;
        $this->viewBuilder()->setLayout('json');
        $this->set('_jsonOptions', JSON_FORCE_OBJECT);
        return parent::beforeFilter($event); // TODO: Change the autogenerated stub
    }

    public function index()
    {

    }

    /**
     * Appel API page d'accueil "www.moodify.com/search"
     * @param null $ville
     * Affiche un array JSON de tous les résultats qu'on a besoin sur la pae
     */
    public function home($ville = null)
    {
        if (!$this->_isTokenValid()) {
            $this->_notAuthenticated();
            return;
        }
        if (is_null($ville)) {
            $this->_errorParameter('ville');
            return;
        } else {
            $this->data['returns']['weather'] = $this->getWeather($ville);
            if (!empty($this->getWeather($ville))) {
                $this->data['returns']['drink_alcohol'] = $this->getAlcoholDrink($this->data['returns']['weather']['condition_key']);
                $this->data['returns']['drink_not_alcohol'] = $this->getNotAlcoholDrink($this->data['returns']['weather']['condition_key']);
                $this->data['returns']['recipe'] = $this->getRecipeRandom();
                $this->data['returns']['series'] = $this->getSeriesRandom();
                $this->data['returns']['activity'] = $this->getActivityByWeather($this->data['returns']['weather']['condition_key']);
            }
        }
        die(json_encode($this->data));
    }

    // Alternative de la weather
    public function home2($ville = null)
    {
        if (!$this->_isTokenValid()) {
            $this->_notAuthenticated();
            return;
        }
        if (is_null($ville)) {
            $this->_errorParameter('ville');
            return;
        } else {
            $this->data['returns']['weather'] = $this->getWeather2($ville);
            if (!empty($this->getWeather2($ville))) {
                $this->data['returns']['drink_alcohol'] = $this->getAlcoholDrink2($this->data['returns']['weather']['condition_key']);
                $this->data['returns']['drink_not_alcohol'] = $this->getNotAlcoholDrink2($this->data['returns']['weather']['condition_key']);
                $this->data['returns']['recipe'] = $this->getRecipeRandom();
                $this->data['returns']['series'] = $this->getSeriesRandom();
                $this->data['returns']['activity'] = $this->getActivityByWeather($this->data['returns']['weather']['condition_key']);
            }
        }
        die(json_encode($this->data));
    }

    ////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
    ///
    /// FONCTIONS TRAITEMENTS APIS EXTERNE
    ///
    ////////////////////////////////////////////////////////////////////////////////////////////////////////////////////

    /**
     * @param $ville
     * @return array météo, condition, condition_key et température de la ville
     */
    public function getWeather($ville)
    {
        $data = array();
        $http = new Client();
        $getData = $http->get('http://www.prevision-meteo.ch/services/json/' . $ville);
        if(empty($getData->json) || !$getData){
            $this->_errorRetourApi('http://www.prevision-meteo.ch/services/json/' . $ville);
            return;
        }
        if(isset($getData->json['errors'])){
            $this->_errorRetourApi('http://www.prevision-meteo.ch/services/json/' . $ville, $getData->json['errors'][0]['text'].". ".$getData->json['errors'][0]['description']);
            return;
        }
        $data['ville'] = $ville;
        $data['condition'] = $getData->json['current_condition']['condition'];
        $data['condition_key'] = $getData->json['current_condition']['condition_key'];
        $data['tmp'] = $getData->json['current_condition']['tmp'];
        $data['humidity'] = $getData->json['current_condition']['humidity'];

        return $data;
    }

    // Other weatherApi (marche mieux j'ai l'impression)
    public function getWeather2($ville)
    {
        $data = array();
        $http = new Client();
        $getData = $http->get('http://api.openweathermap.org/data/2.5/weather?units=metric&lang=fr&q=' . $ville . '&appid=' . self::API_KEY_WEATHER);
        if($getData->json['cod'] != 200){
            $this->_errorRetourApi('http://api.openweathermap.org/data/2.5/weather?units=metric&lang=fr&q=' . $ville . '&appid=' . self::API_KEY_WEATHER, $getData->json['message']);
            return;
        }
        $data['ville'] = $ville;
        $data['condition'] = $getData->json['weather'][0]['description'];
        $data['condition_key'] = $getData->json['weather'][0]['id'];
        $data['temperature'] = $getData->json['main']['temp'];
        $data['icon'] = $getData->json['weather'][0]['icon'];

        return $data;
    }

    public function getAlcoholDrink($condition_key = null)
    {
        $data = array();
        $http = new Client();

        $taste = $this->_getTasteByConditionWeather($condition_key);
        list($period_date, $with_ice_cubes) = $this->_getPeriodDate();

        $urlAlcohol = "http://addb.absolutdrinks.com/drinks/alcoholic/tasting/" . $taste . "/for/" . $period_date . $with_ice_cubes . "?apiKey=".self::API_KEY_DRINKS;
        $responseAlcohol = $http->get($urlAlcohol);
        if($responseAlcohol->json['totalResult'] == 0){
            $this->_errorRetourApi($urlAlcohol);
            return;
        }
        $nbAlcohol = count($responseAlcohol->json['result']) - 1;
        $nAlcohol = rand(0, $nbAlcohol);

        $data['name'] = $responseAlcohol->json['result'][$nAlcohol]['name'];
        $data['url_video'] = $responseAlcohol->json['result'][$nAlcohol]['videos'][0]['video'];

        return $data;
    }

    public function getNotAlcoholDrink($condition_key = null)
    {
        $data = array();
        $http = new Client();

        $taste = $this->_getTasteByConditionWeather($condition_key);
        list($period_date, $with_ice_cubes) = $this->_getPeriodDate();

        $urlNotAlcohol = "http://addb.absolutdrinks.com/drinks/not/alcoholic/tasting/" . $taste . "/for/" . $period_date . $with_ice_cubes . "?apiKey=".self::API_KEY_DRINKS;
        $responseNotAlcohol = $http->get($urlNotAlcohol);
        if($responseNotAlcohol->json['totalResult'] == 0){
            $this->_errorRetourApi($urlNotAlcohol);
            return;
        }
        $nbNotAlcohol = count($responseNotAlcohol->json['result']) - 1;
        $nNotAlcohol = rand(0, $nbNotAlcohol);

        $data['name'] = $responseNotAlcohol->json['result'][$nNotAlcohol]['name'];
        $data['url_video'] = $responseNotAlcohol->json['result'][$nNotAlcohol]['videos'][0]['video'];

        return $data;
    }

    public function getAlcoholDrink2($condition_key = null)
    {
        $data = array();
        $http = new Client();

        $taste = $this->_getTasteByConditionWeather2($condition_key);
        list($period_date, $with_ice_cubes) = $this->_getPeriodDate();

        $urlAlcohol = "http://addb.absolutdrinks.com/drinks/alcoholic/tasting/" . $taste . "/for/" . $period_date . $with_ice_cubes . "?apiKey=".self::API_KEY_DRINKS;
        $responseAlcohol = $http->get($urlAlcohol);
        if($responseAlcohol->json['totalResult'] == 0){
            $this->_errorRetourApi($urlAlcohol);
            return;
        }
        $nbAlcohol = count($responseAlcohol->json['result']) - 1;
        $nAlcohol = rand(0, $nbAlcohol);

        $data['name'] = $responseAlcohol->json['result'][$nAlcohol]['name'];
        $data['url_video'] = $responseAlcohol->json['result'][$nAlcohol]['videos'][0]['video'];

        return $data;
    }

    public function getNotAlcoholDrink2($condition_key = null)
    {
        $data = array();
        $http = new Client();

        $taste = $this->_getTasteByConditionWeather2($condition_key);
        list($period_date, $with_ice_cubes) = $this->_getPeriodDate();

        $urlNotAlcohol = "http://addb.absolutdrinks.com/drinks/not/alcoholic/tasting/" . $taste . "/for/" . $period_date . $with_ice_cubes . "?apiKey=".self::API_KEY_DRINKS;
        $responseNotAlcohol = $http->get($urlNotAlcohol);
        if($responseNotAlcohol->json['totalResult'] == 0){
            $this->_errorRetourApi($urlNotAlcohol);
            return;
        }
        $nbNotAlcohol = count($responseNotAlcohol->json['result']) - 1;
        $nNotAlcohol = rand(0, $nbNotAlcohol);

        $data['name'] = $responseNotAlcohol->json['result'][$nNotAlcohol]['name'];
        $data['url_video'] = $responseNotAlcohol->json['result'][$nNotAlcohol]['videos'][0]['video'];

        return $data;
    }

    public function getRecipeRandom($ingredient = 'citron')
    {
        if (!$this->_isTokenValid()) {
            $this->_notAuthenticated();
            return;
        }
        if (is_null($ingredient)) {
            $this->_errorParameter('ingredient');
            return;
        }
        $data = array();
        $http = new Client();
        $url ="http://food2fork.com/api/search?key=".self::API_KEY_FOOD."&Accept=application/json&q=".$ingredient;
        $responseFood = $http->get($url);
        if($responseFood->json['count'] == 0){
            $this->_errorRetourApi($url);
            return;
        }else{
            for($i=0; $i<4; $i++){
                $data['recipe_id'] = $responseFood->json['recipes'][$i]['recipe_id'];
                $data['title'] = $responseFood->json['recipes'][$i]['title'];
                $data['image'] = $responseFood->json['recipes'][$i]['image_url'];
                $data['source_url'] = $responseFood->json['recipes'][$i]['source_url'];
            }
        }
        return $data;
    }

    public function getSeriesRandom()
    {
        if (!$this->_isTokenValid()) {
            $this->_notAuthenticated();
            return;
        }
        $data = array();
        $http = new Client();
        $url ="https://api.betaseries.com/shows/random?nb=100&key=cb1d200d4a43";
        $responseSerie = $http->get($url);
        if(empty($responseSerie->json['shows'])){
            $this->_errorRetourApi($url);
            return;
        }else{
            $nbSeries = count($responseSerie->json['shows']);
            $nSeries= rand(0, $nbSeries);
            $data['title'] = $responseSerie->json['shows'][$nSeries]['title'];
            $data['description'] = $responseSerie->json['shows'][$nSeries]['description'];
            $data['image'] = $responseSerie->json['shows'][$nSeries]['images']['poster'];
        }
        return $data;
    }

    public function getActivityByWeather($weather_condition_key)
    {
        if (!$this->_isTokenValid()) {
            $this->_notAuthenticated();
            return;
        }
        if (is_null($weather_condition_key)) {
            $this->_errorParameter('weather_condition_key (Snow, Rainy)');
            return;
        }else{
            $weather = $this->_getWeatherByConditionWeather($weather_condition_key);
            $data = array();
            $activities_table = TableRegistry::get('activity');
            $activities = $activities_table->find('all')
                ->where(['weather' => $weather])
                ->toArray();
            if(empty($activities)){
                $this->_errorRetourApi(null);
                return;
            }else{
                $activity1 = rand(0, (count($activities)-1));
                array_push($data, $activities[$activity1]->name);
                $same_activity = true;
                while($same_activity){
                    $activity2 = rand(0, (count($activities)-1));
                    if($activities[$activity1]->name != $activities[$activity2]->name){
                        $same_activity = false;
                    }
                }
                array_push($data, $activities[$activity2]->name);
            }
        }
        return $data;
    }

    private function _getTasteByConditionWeather($condition_key)
    {
        $taste = null;
        switch ($condition_key) {
            case "nuit-nuageuse":
            case "brouillard":
            case "nuit-avec-developpement-nuageux":
            case "fortement-nuageux":
                $taste = "bitter";
                break;
            case "stratus":
            case "ciel-voile":
            case "nuit-avec-averses-de-neige-faible":
            case "developpement-nuageux":
                $taste = "sour";
                break;
            case "nuit-faiblement-orageuse":
            case "eclaircies":
            case "ensoleille":
                $taste = "fresh";
                break;
            case "orage-modere":
            case "fortement-orageux":
            case "nuit-faiblement-orageuse":
                $taste = "spicy";
                break;
            case "nuit-avec-averses-de-neige-faible":
            case "averses-de-pluie-faible":
            case "nuit-claire-et-stratus":
            case "nuit-legerement-voilee":
            case "nuit-claire":
                $taste = "berry";
                break;
            case "faiblement-orageux":
            case "couvert-avec-averses":
            case "stratus-se-dissipant":
                $taste = "herb";
                break;
            case "neige-forte":
            case "pluie-forte":
            case "nuit-avec-averses":
            case "pluie-moderee":
                $taste = "spicy";
                break;
            case "averses-de-pluie-forte":
            case "pluie-et-neige-melee-moderee":
            case "pluie-et-neige-melee-faible":
            case "pluie-et-neige-melee-forte":
            case "averses-de-pluie-moderee":
                $taste = "fruity";
                break;
            case "averses-de-neige-faible":
            case "neige-moderee":
            case "neige-faible":
            case "pluie-faible":
            case "nuit-bien-degagee":
            case "faibles-passages-nuageux":
            case "faiblement-nuageux":
                $taste = "sweet";
                break;
        }
        return $taste;
    }

    private function _getWeatherByConditionWeather($condition_key)
    {
        $weather = null;
        switch ($condition_key) {
            case "eclaircies":
            case "ensoleille":
            case "nuit-claire-et-stratus":
            case "nuit-legerement-voilee":
            case "nuit-claire":
            case "stratus-se-dissipant":
            case "nuit-bien-degagee":
            $weather = "Sunny";
                break;
            case "nuit-nuageuse":
            case "brouillard":
            case "nuit-avec-developpement-nuageux":
            case "fortement-nuageux":
            case "stratus":
            case "ciel-voile":
            case "developpement-nuageux":
            case "faiblement-orageux":
            case "faibles-passages-nuageux":
            case "faiblement-nuageux":
            $weather = "Windy";
                break;
            case "pluie-faible":
            case "averses-de-pluie-faible":
            case "pluie-forte":
            case "nuit-avec-averses":
            case "pluie-moderee":
            case "averses-de-pluie-forte":
            case "averses-de-pluie-moderee":
            case "couvert-avec-averses":
            case "nuit-faiblement-orageuse":
            case "orage-modere":
            case "fortement-orageux":
            case "nuit-faiblement-orageuse":
            $weather = "Rainy";
                break;
            case "averses-de-neige-faible":
            case "nuit-avec-averses-de-neige-faible":
            case "neige-moderee":
            case "neige-faible":
            case "pluie-et-neige-melee-moderee":
            case "pluie-et-neige-melee-faible":
            case "pluie-et-neige-melee-forte":
            case "neige-forte":
            case "nuit-avec-averses-de-neige-faible":
                $weather = "Snow";
                break;
        }
        return $weather;
    }

    private function _getTasteByConditionWeather2($condition_key)
    {
        $taste = null;
        if(($condition_key >= 200 && $condition_key <= 232) || ($condition_key >= 300 && $condition_key <= 321)){
            $taste = "spicy";
        }else if(($condition_key == 800)){
            $taste = "fresh";
        }else if(($condition_key >= 300 && $condition_key <= 321)){
            $taste = "herb";
        }else if(($condition_key >= 500 && $condition_key <= 531)){
            $taste = "berry";
        }else if(($condition_key >= 600 && $condition_key <= 622)){
            $taste = "sour";
        }else if(($condition_key >= 701 && $condition_key <= 781)){
            $taste = "bitter";
        }else if(($condition_key >= 801 && $condition_key <= 804)){
            $taste = "fruity";
        }else{
            $taste = "sweet";
        }

        return $taste;
    }

    private function _getPeriodDate()
    {
        date_default_timezone_set('Europe/Paris');
        $date_hour = date('G');
        if ($date_hour >= 28) {
            if ($date_hour >= 05 && $date_hour <= 17) {
                $date = "afternoon";
            } else if ($date_hour >= 17 && $date_hour <= 19) {
                $date = "pre-dinner";
            } else if ($date_hour >= 19 && $date_hour <= 21) {
                $date = "after-dinner";
            } else if ($date_hour >= 21 && $date_hour <= 05) {
                $date = "evening";
            }
            $with_ice_cubes = "/with/ice-cubes";
        } else {

            if ($date_hour >= 05 && $date_hour <= 17) {
                $date = "afternoon";
            } else if ($date_hour >= 17 && $date_hour <= 19) {
                $date = "pre-dinner";
            } else if ($date_hour >= 19 && $date_hour <= 21) {
                $date = "after-dinner";
            } else if ($date_hour >= 21 && $date_hour <= 05) {
                $date = "evening";
            }
            $with_ice_cubes = "";
        }
        return array($date, $with_ice_cubes);
    }

    public function recipe($recettes)
    {
        if (!$this->_isTokenValid()) {
            $this->_notAuthenticated();
            return;
        }
        $data = array();
        $http = new Client();
        if(empty($recettes)) {
            $this->_errorParameter('recettes (id des recettes à afficher)');
            return;
        }else{
            foreach ($recettes as $recetteId){
                $response = $http->post('http://food2fork.com/api/get', [
                    'key' => self::API_KEY_FOOD,
                    'rId' => $recetteId,
                    'Accept' => 'application/json'
                ]);
                debug($response); exit();
                if($response->json[''] == 0){
                    $this->_errorRetourApi('https://cors-anywhere.herokuapp.com/http://food2fork.com/api/get', "Paramètres POST : key = ".self::API_KEY_FOOD . " | rID : ".$recetteId. " | Accept : application/json");
                    return;
                }
            }
        }
        return $data;
    }

    public function drinks($taste = null)
    {
        if (!$this->_isTokenValid()) {
            $this->_notAuthenticated();
            return;
        }
        if (is_null($taste)) {
            $this->_errorParameter('taste (type de boisson : fraiche, épicée, ...)');
            return;
        } else {
            $http = new Client();

            $urlAlcohol = "http://addb.absolutdrinks.com/drinks/alcoholic/tasting/" . $taste . "?apiKey=".self::API_KEY_DRINKS;
            $responseAlcohol = $http->get($urlAlcohol);
            if($responseAlcohol->json['totalResult'] == 0){
                $this->_errorRetourApi($urlAlcohol);
                return;
            }
            $nbAlcohol = count($responseAlcohol->json['result']) - 1;
            $nAlcohol = rand(0, $nbAlcohol);

            $this->data['returns']['drink_alcohol'] = $responseAlcohol->json['result'][$nAlcohol];

            $urlNotAlcohol = "http://addb.absolutdrinks.com/drinks/not/alcoholic/tasting/" . $taste . "?apiKey=".self::API_KEY_DRINKS;
            $responseNotAlcohol = $http->get($urlNotAlcohol);
            if($responseNotAlcohol->json['totalResult'] == 0){
                $this->_errorRetourApi($urlNotAlcohol);
                return;
            }
            $nbNotAlcohol = count($responseNotAlcohol->json['result']) - 1;
            $nNotAlcohol = rand(0, $nbNotAlcohol);

            $this->data['returns']['drink_not_alcohol'] = $responseNotAlcohol->json['result'][$nNotAlcohol];
        }
        die(json_encode($this->data));
    }


    ////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
    ///
    /// FONCTIONS TRAITEMENT INTERNE API
    ///
    ////////////////////////////////////////////////////////////////////////////////////////////////////////////////////

    // ----------------------------------------------------------------------------------------------------------------
    public function getToken()
    {
        $in = $this->request->data;     // En POST
        $in = $this->request->query;    // En GET

        if (empty($in['email']) or empty($in['password'])) {
            $this->_notAuthenticated();
            return;
        }

        $email = $in['email'];
        $password = Security::hash($in['password'], 'sha1', true);

        // Vérif du user/mot de passe
        $users_table = TableRegistry::get('users');
        $user = $users_table->find('all')
            ->where(['email' => $email, 'password' => $password])
            ->first();

        if (empty($user)) {
            $this->_notAuthenticated();
            return;
        }
        $token = $this->TokenGenerator->generate($user->id, "+6 days +0 hours");
        $this->data['returns']['token'] = $token;
        die(json_encode($this->data));
    }

    private function _isTokenValid()
    {
        $in = $this->request->data;     // En POST
        $in = $this->request->query;    // En GET
        if (!empty($in['token'])) {
            // Décode le token
            if($this->TokenGenerator->read($in['token'])){
                return true;
            }
        }
        return false;
    }

    private function _notAuthenticated()
    {
        $this->data['return_code'] = self::RETURN_CODE_ERROR_AUTH;
        $this->data['error'] = "Echec lors de l'authentification";
        die(json_encode($this->data));
    }

    private function _errorParameter($missing_param)
    {
        $this->data['return_code'] = self::RETURN_CODE_ERROR_MISSING_PARAMETER;
        $this->data['error'] = "Paramètre '$missing_param' manquant";
        die(json_encode($this->data));
    }

    private function _errorRetourApi($url_api, $error_supp = "")
    {
        $this->data['return_code'] = self::RETURN_CODE_ERROR_RETOUR_API;
        $this->data['error'] = "L'API n'a retourné aucune données, ou une donnée non valide. (URL : ".$url_api."). ".((!empty($error_supp))?"Infos erreur supp : ".$error_supp:"");
        die(json_encode($this->data));
    }

}