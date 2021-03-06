<?php

namespace App\Controller;

use Cake\Event\Event;
use Cake\Http\Client;
use Cake\ORM\TableRegistry;
use Cake\Utility\Security;
use App\Model;
use Google_Client;

class ApiController extends AppController
{

    const RETURN_CODE_ERROR_AUTH = -3;
    const RETURN_CODE_ERROR_MISSING_PARAMETER = -2;
    const RETURN_CODE_ERROR_RETOUR_API = -1;
    const RETURN_CODE_SUCCESS = 0;

    const API_KEY_WEATHER = "6deb28875db5aae777450b29bda0f889";
    const API_KEY_DRINKS = "328da11a6e5144929f6bf83e1dc9e5da";
    const API_KEY_FOOD = "527fefc488fc4e3f81e5d4204daa88de";

    const GOOGLE_OAUTH_CLIENT_ID = '724346200475-sj3iure20vb2mse5m6ogjtsg9kb5qma2.apps.googleusercontent.com';
    const GOOGLE_OAUTH_CLIENT_SECRET = 'Vf5JkHeTmcXUxQHyJFVfNro9';
    const GOOGLE_OAUTH_REDIRECT_URI = 'http://localhost:8080/';
    const GOOGLE_OAUTH_REDIRECT_URI2 = 'http://api.moodify.dev/api/redirectConnectGoogle/';
    const SOCIAL_ID_GOOGLE = 0;

    private $data = array('return_code' => self::RETURN_CODE_SUCCESS, 'error' => "", 'returns' => array());

    public function initialize()
    {
        parent::initialize(); // TODO: Change the autogenerated stub
    }

    public function beforeFilter(Event $event)
    {
        header('Access-Control-Allow-Origin: *');
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
     * Affiche un array JSON de tous les résultats qu'on a besoin sur la page
     */
    public function home($ville = null)
    {
        if (is_null($ville)) {
            $this->_errorParameter('ville');
            return;
        } else {
            $this->data['returns']['weather'] = $this->getWeather($ville);
            if (!empty($this->data['returns']['weather'])) {
                $this->data['returns']['drink_alcohol'] = $this->getAlcoholDrink($this->data['returns']['weather']['condition_key']);
                $this->data['returns']['drink_not_alcohol'] = $this->getNotAlcoholDrink($this->data['returns']['weather']['condition_key']);
                $this->data['returns']['activities'] = $this->getActivityByWeather($this->data['returns']['weather']['condition_key']);
            }
            $this->data['returns']['recipe'] = $this->getRecipeRandom();
            $this->data['returns']['series'] = $this->getSeriesRandom();
        }
        die(json_encode($this->data));
    }

    // Retourne la recette en fonction de l'ID/des ID de la/les recette en paramètre
    public function recipe($recettes = null)
    {
        $http = new Client();
        if (empty($recettes)) {
            $this->_errorParameter('recettes (id des recettes à afficher)');
            return;
        } else {
            $recettes = explode(",", $recettes);
            foreach ($recettes as $key => $recetteId) {
                $response = $http->post('http://food2fork.com/api/get', [
                    'key' => self::API_KEY_FOOD,
                    'rId' => $recetteId,
                    'Accept' => 'application/json'
                ]);
                if (empty($response->json) && $response->isOk()) {
                    $this->_errorRetourApi('http://food2fork.com/api/get');
                    return;
                }
                if (empty($response->json['recipe'])) {
                    $this->_errorRetourApi('https://cors-anywhere.herokuapp.com/http://food2fork.com/api/get', "Paramètres POST : key = " . self::API_KEY_FOOD . " | rID : " . $recetteId . " | Accept : application/json");
                    return;
                }
                $this->data['returns']['recipe'][$key] = $response->json['recipe'];
            }
        }
        die(json_encode($this->data));
    }

    // Retourne 4 recettes aléatoires
    public function food($ingredient)
    {
        $recipes = $this->getRecipeRandom($ingredient);
        $this->data['returns']['recipes'] = $recipes;
        die(json_encode($this->data));
    }

    // Retourne 1 boisson alcoolisée et une boisson non alcoolisée aléatoire
    public function drinks($taste = null)
    {
        if (is_null($taste)) {
            $this->_errorParameter('taste (type de boisson : fraiche, épicée, ...)');
            return;
        } else {
            $http = new Client();

            $urlAlcohol = "http://addb.absolutdrinks.com/drinks/alcoholic/tasting/" . $taste . "?apiKey=" . self::API_KEY_DRINKS;
            $responseAlcohol = $http->get($urlAlcohol);
            if (empty($responseAlcohol->json) && $responseAlcohol->isOk()) {
                $this->_errorRetourApi($urlAlcohol);
                return;
            }
            if ($responseAlcohol->json['totalResult'] == 0) {
                $this->_errorRetourApi($urlAlcohol);
                return;
            }
            $nbAlcohol = count($responseAlcohol->json['result']) - 1;
            $nAlcohol = rand(0, $nbAlcohol);

            $this->data['returns']['drink_alcohol']['name'] = $responseAlcohol->json['result'][$nAlcohol]['name'];
            $this->data['returns']['drink_alcohol']['url_video'] = "https://www.youtube.com/embed/" . $responseAlcohol->json['result'][$nAlcohol]['videos'][0]['video'];

            $urlNotAlcohol = "http://addb.absolutdrinks.com/drinks/not/alcoholic/tasting/" . $taste . "?apiKey=" . self::API_KEY_DRINKS;
            $responseNotAlcohol = $http->get($urlNotAlcohol);
            if ($responseNotAlcohol->json['totalResult'] == 0) {
                $this->_errorRetourApi($urlNotAlcohol);
                return;
            }
            $nbNotAlcohol = count($responseNotAlcohol->json['result']) - 1;
            $nNotAlcohol = rand(0, $nbNotAlcohol);

            $this->data['returns']['drink_not_alcohol']['name'] = $responseAlcohol->json['result'][$nNotAlcohol]['name'];
            $this->data['returns']['drink_not_alcohol']['url_video'] = "https://www.youtube.com/embed/" . $responseAlcohol->json['result'][$nNotAlcohol]['videos'][0]['video'];
        }
        die(json_encode($this->data));
    }

    // Retourne une série aléatoire
    public function TV($type_media)
    {
        $http = new Client();
        if ($type_media == "film") {
            $url = "http://api.betaseries.com/movies/discover?key=cb1d200d4a43&type=popular";
            $responseSerie = $http->get($url);
            if (empty($responseSerie->json) && $responseSerie->isOk()) {
                $this->_errorRetourApi($url);
                return;
            }
            for ($i = 0; $i < 4; $i++) {
                $rand = rand(0, count($responseSerie->json['movies']) - 4);
                $this->data['returns']['media'][$i] = $responseSerie->json['movies'][$rand];

                $urlPoster = "https://api.betaseries.com/movies/movie?key=cb1d200d4a43&id=".$responseSerie->json['movies'][$rand]['id'];
                $responseSeriePoster = $http->get($urlPoster);
                $this->data['returns']['media'][$i]['poster'] = $responseSeriePoster->json['movie']['poster'];
                $this->data['returns']['media'][$i]['description'] = $responseSeriePoster->json['movie']['synopsis'];
            }
        } else if ($type_media == "serie" || $type_media == "série") {
            $url = "http://api.betaseries.com/shows/discover?key=cb1d200d4a43&type=popular";
            $responseSerie = $http->get($url);
            if (empty($responseSerie->json) && $responseSerie->isOk()) {
                $this->_errorRetourApi($url);
                return;
            }
            for ($i = 0; $i < 4; $i++) {
                $rand = rand(0, count($responseSerie->json['shows']) - 4);
                $this->data['returns']['media'][$i] = $responseSerie->json['shows'][$rand];

                $urlPoster = "https://api.betaseries.com/shows/display?key=cb1d200d4a43&id=".$responseSerie->json['shows'][$rand]['id'];
                $responseSeriePoster = $http->get($urlPoster);
                $this->data['returns']['media'][$i]['poster'] = $responseSeriePoster->json['show']['images']['show'];
                $this->data['returns']['media'][$i]['description'] = $responseSeriePoster->json['show']['description'];
            }
        }
        die(json_encode($this->data));
    }

    // Retourne une activité aléatoire
    public function activity()
    {
        $activities_table = TableRegistry::get('activity');
        $activities = $activities_table->find('all')
            ->toArray();
        if (empty($activities)) {
            $this->_errorRetourApi(null);
            return;
        } else {
            $activity1 = rand(0, (count($activities) - 1));
            $this->data['returns']['activities'][0] = $activities[$activity1]->name;
            $same_activity = true;
            while ($same_activity) {
                $activity2 = rand(0, (count($activities) - 1));
                if ($activities[$activity1]->name != $activities[$activity2]->name) {
                    $same_activity = false;
                }
            }
            $this->data['returns']['activities'][1] = $activities[$activity2]->name;
            $same_activity = true;
            while ($same_activity) {
                $activity3 = rand(0, (count($activities) - 1));
                if ($activities[$activity3]->name != $activities[$activity1]->name && $activities[$activity3]->name != $activities[$activity2]->name) {
                    $same_activity = false;
                }
            }
            $this->data['returns']['activities'][2] = $activities[$activity3]->name;
        }
        die(json_encode($this->data));
    }

    // Retourne une activité aléatoire
    public function upcomingMovies()
    {
        $http = new Client();
        $url = "http://api.betaseries.com/movies/discover?key=cb1d200d4a43&type=upcoming";
        $responseSerie = $http->get($url);
        if (empty($responseSerie->json) && $responseSerie->isOk()) {
            $this->_errorRetourApi($url);
            return;
        }
        for ($i = 0; $i < 4; $i++) {
            $rand = rand(0, count($responseSerie->json['movies']) - 4);
            $this->data['returns']['movies'][$i] = $responseSerie->json['movies'][$rand];

            $urlPoster = "https://api.betaseries.com/movies/movie?key=cb1d200d4a43&id=".$responseSerie->json['movies'][$rand]['id'];
            $responseSeriePoster = $http->get($urlPoster);
            $this->data['returns']['movies'][$i]['poster'] = $responseSeriePoster->json['movie']['poster'];
            $this->data['returns']['movies'][$i]['description'] = $responseSeriePoster->json['movie']['synopsis'];

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
        if (empty($getData->json) && $getData->isOk()) {
            $this->_errorRetourApi('http://www.prevision-meteo.ch/services/json/');
            return;
        }
        if (empty($getData->json) || !$getData) {
            $this->_errorRetourApi('http://www.prevision-meteo.ch/services/json/' . $ville);
            return;
        }
        if (isset($getData->json['errors'])) {
            $this->_errorRetourApi('http://www.prevision-meteo.ch/services/json/' . $ville, $getData->json['errors'][0]['text'] . ". " . $getData->json['errors'][0]['description']);
            return;
        }
        $data['ville'] = $getData->json['city_info']['name'];
        $data['condition'] = $getData->json['current_condition']['condition'];
        $data['condition_key'] = $getData->json['current_condition']['condition_key'];
        $data['tmp'] = $getData->json['current_condition']['tmp'];
        $data['humidity'] = $getData->json['current_condition']['humidity'];

        switch ($getData->json['current_condition']['wnd_dir']) {
            case "N":
                $data['wind'] = $getData->json['current_condition']['wnd_spd'] . "km/h ↑";
                break;
            case "S":
                $data['wind'] = $getData->json['current_condition']['wnd_spd'] . "km/h ↓";
                break;
            case "E":
                $data['wind'] = $getData->json['current_condition']['wnd_spd'] . "km/h →";
                break;
            case "O":
                $data['wind'] = $getData->json['current_condition']['wnd_spd'] . "km/h ←";
                break;
            case "NE":
                $data['wind'] = $getData->json['current_condition']['wnd_spd'] . "km/h ↗";
                break;
            case "NO":
                $data['wind'] = $getData->json['current_condition']['wnd_spd'] . "km/h ↖";
                break;
            case "SE":
                $data['wind'] = $getData->json['current_condition']['wnd_spd'] . "km/h ↘";
                break;
            case "SO":
                $data['wind'] = $getData->json['current_condition']['wnd_spd'] . "km/h ↙";
                break;
        }

        return $data;
    }

    // Retourne la recette en fonction de l'ID/des ID de la/les recette en paramètre
    public function getIngredientsFood($recette_id = null)
    {
        $data = array();
        $http = new Client();
        if (empty($recette_id)) {
            $this->_errorParameter('recette (id des recettes à afficher)');
            return;
        } else {
            $response = $http->post('http://food2fork.com/api/get', [
                'key' => self::API_KEY_FOOD,
                'rId' => $recette_id,
                'Accept' => 'application/json'
            ]);
            if (empty($response->json) && $response->isOk()) {
                $this->_errorRetourApi('http://food2fork.com/api/get');
                return;
            }
            if (empty($response->json['recipe'])) {
                $this->_errorRetourApi('https://cors-anywhere.herokuapp.com/http://food2fork.com/api/get', "Paramètres POST : key = " . self::API_KEY_FOOD . " | rID : " . $recette_id . " | Accept : application/json");
                return;
            }
            foreach ($response->json['recipe']['ingredients'] as $key => $ingredient) {
                $data[$key] = $ingredient;
            }
        }
        return $data;
    }

    /**
     * @param null $condition_key
     * @return array|void d'une boisson alcoolisée
     */
    public function getAlcoholDrink($condition_key = null)
    {
        $data = array();
        $http = new Client();

        $taste = $this->_getTasteByConditionWeather($condition_key);
        list($period_date, $with_ice_cubes) = $this->_getPeriodDate();

        $urlAlcohol = "http://addb.absolutdrinks.com/drinks/alcoholic/tasting/" . $taste . "/for/" . $period_date . $with_ice_cubes . "?apiKey=" . self::API_KEY_DRINKS;
        $responseAlcohol = $http->get($urlAlcohol);
        if (empty($responseAlcohol->json) && $responseAlcohol->isOk()) {
            $this->_errorRetourApi($urlAlcohol);
            return;
        }
        if ($responseAlcohol->json['totalResult'] == 0) {
            $this->_errorRetourApi($urlAlcohol);
            return;
        }
        $nbAlcohol = count($responseAlcohol->json['result']) - 1;
        $nAlcohol = rand(0, $nbAlcohol);

        $data['name'] = $responseAlcohol->json['result'][$nAlcohol]['name'];
        if (!empty($responseAlcohol->json['result'][$nAlcohol]['videos'][0]['video'])) {
            $data['url_video'] = "https://www.youtube.com/embed/" . $responseAlcohol->json['result'][$nAlcohol]['videos'][0]['video'];
        }
        return $data;
    }

    /**
     * @param null $condition_key
     * @return array|void d'une boisson non alcoolisée
     */
    public function getNotAlcoholDrink($condition_key = null)
    {
        $data = array();
        $http = new Client();

        $taste = $this->_getTasteByConditionWeather($condition_key);
        list($period_date, $with_ice_cubes) = $this->_getPeriodDate();

        $urlNotAlcohol = "http://addb.absolutdrinks.com/drinks/not/alcoholic/tasting/" . $taste . "/for/" . $period_date . $with_ice_cubes . "?apiKey=" . self::API_KEY_DRINKS;
        $responseNotAlcohol = $http->get($urlNotAlcohol);
        if (empty($responseNotAlcohol->json) && $responseNotAlcohol->isOk()) {
            $this->_errorRetourApi($urlNotAlcohol);
            return;
        }
        if ($responseNotAlcohol->json['totalResult'] == 0) {
            $this->_errorRetourApi($urlNotAlcohol);
            return;
        }
        $nbNotAlcohol = count($responseNotAlcohol->json['result']) - 1;
        $nNotAlcohol = rand(0, $nbNotAlcohol);

        $data['name'] = $responseNotAlcohol->json['result'][$nNotAlcohol]['name'];
        $data['url_video'] = "https://www.youtube.com/embed/" . $responseNotAlcohol->json['result'][$nNotAlcohol]['videos'][0]['video'];

        return $data;
    }

    /**
     * @param string $ingredient
     * @return array|void de 4 recettes avec l'ingrédient envoyé en paramètre
     */
    public function getRecipeRandom($ingredient = null)
    {
        $data = array();
        $http = new Client();
        $url = "http://food2fork.com/api/search?key=" . self::API_KEY_FOOD . "&Accept=application/json".((!is_null($ingredient))?"&q=".$ingredient:"");
        $responseFood = $http->get($url);
        if (empty($responseFood->json) && $responseFood->isOk()) {
            $this->_errorRetourApi($url);
            return;
        }
        /*if (!empty($responseFood->json['error']) && $responseFood->json['error'] == 'limit') {
            return $data[0]['error'] = 'limite atteinte';
        }*/
        if(!empty($responseFood->json['error'])){
            if ($responseFood->json['error'] == 'limit') {
                //$this->_errorRetourApi($url);
                return $data[0]['error'] = 'limite atteinte';
            }
        }
        $nb_aleatoire = rand(0, $responseFood->json['count'] - 3);
        for ($i = $nb_aleatoire; $i < ($nb_aleatoire + 3); $i++) {
            $data[$i]['recipe_id'] = $responseFood->json['recipes'][$i]['recipe_id'];
            $data[$i]['title'] = $responseFood->json['recipes'][$i]['title'];
            $data[$i]['social_rank'] = $responseFood->json['recipes'][$i]['social_rank'];
            if (!empty($responseFood->json['recipes'][$i]['image_url']) && $responseFood->json['recipes'][$i]['image_url'] != null) {
                $data[$i]['image'] = $responseFood->json['recipes'][$i]['image_url'];
            } else {
                $data[$i]['image'] = "http://via.placeholder.com/150x300?text=No image";
            }
            $data[$i]['source_url'] = $responseFood->json['recipes'][$i]['source_url'];
            $data[$i]['difficulty'] = (int)($responseFood->json['recipes'][$i]['social_rank'] / (9 + lcg_value() * (abs(11 - 9))));
            $data[$i]['duration_preparation'] = rand(15, 60);
            $data[$i]['cost'] = $responseFood->json['recipes'][$i]['social_rank'] / (9 + lcg_value() * (abs(13 - 9)));

            // Ingrédients
            $data[$i]['ingredients'] = $this->getIngredientsFood($responseFood->json['recipes'][$i]['recipe_id']);
        }
        return $data;
    }

    /**
     * @return array|void d'une série aléatoire
     */
    public
    function getSeriesRandom()
    {
        $data = array();
        $http = new Client();
        $url = "https://api.betaseries.com/shows/random?nb=100&key=cb1d200d4a43";
        $responseSerie = $http->get($url);
        if (empty($responseSerie->json) && $responseSerie->isOk()) {
            $this->_errorRetourApi($url);
            return;
        }
        if (empty($responseSerie->json['shows'])) {
            $this->_errorRetourApi($url);
            return;
        } else {
            $nbSeries = count($responseSerie->json['shows']);
            $nSeries = rand(0, $nbSeries);
            $data['title'] = $responseSerie->json['shows'][$nSeries]['title'];
            $data['description'] = $responseSerie->json['shows'][$nSeries]['description'];
            if (!empty($responseSerie->json['shows'][$nSeries]['images']['poster']) && $responseSerie->json['shows'][$nSeries]['images']['poster'] != null) {
                $data['image'] = $responseSerie->json['shows'][$nSeries]['images']['poster'];
            } else {
                $data['image'] = "http://via.placeholder.com/150x300?text=No image";
            }
        }
        return $data;
    }

    /**
     * @param $weather_condition_key
     * @return array|void de 2 activités en fonction de la météo
     */
    public
    function getActivityByWeather($weather)
    {
        if (is_null($weather)) {
            $this->_errorParameter('weather (Snow, Rainy)');
            return;
        } else {
            $data = array();
            $activities_table = TableRegistry::get('activity');
            $activities = $activities_table->find('all')
                ->where(['weather' => $weather])
                ->toArray();
            if (empty($activities)) {
                return $data[0]['error'] = "no activities";
            } else {
                $activity1 = rand(0, (count($activities) - 1));
                array_push($data, $activities[$activity1]->name);
                $same_activity = true;
                while ($same_activity) {
                    $activity2 = rand(0, (count($activities) - 1));
                    if ($activities[$activity1]->name != $activities[$activity2]->name) {
                        $same_activity = false;
                    }
                }
                array_push($data, $activities[$activity2]->name);
                $same_activity = true;
                while ($same_activity) {
                    $activity3 = rand(0, (count($activities) - 1));
                    if ($activities[$activity3]->name != $activities[$activity1]->name && $activities[$activity3]->name != $activities[$activity2]->name) {
                        $same_activity = false;
                    }
                }
                array_push($data, $activities[$activity3]->name);
            }
        }
        return $data;
    }

    private
    function _getTasteByConditionWeather($condition_key)
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

    private
    function _getWeatherByConditionWeather($condition_key)
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

    private
    function _getPeriodDate()
    {
        date_default_timezone_set('Europe/Paris');
        $date_hour = date('G');
        $date = "afternoon";
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


////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
///
/// FONCTIONS TRAITEMENT INTERNE API
///
////////////////////////////////////////////////////////////////////////////////////////////////////////////////////

// Connexion utilisateur
    public
    function connect()
    {
        $in = $this->request->data;     // En POST

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
        $this->data['returns']['user'] = json_encode($user);
        die(json_encode($this->data));
    }

// Création utilisateur
    public
    function register()
    {
        $in = $this->request->data;     // En POST

        if (empty($in['firstname']) or empty($in['lastname']) or empty($in['email']) or empty($in['password']) or empty($in['password_confirm'])) {
            $this->_errorRetourApi('/register/', "Veuillez remplir tous les champs");
            return;
        }

        $users_table = TableRegistry::get('users');
        $user_exist = $users_table->find('all')
            ->where(['email' => $in['email']])
            ->first();
        if (!empty($user_exist)) {
            $this->_errorRetourApi('/register/', "Un utilisateur existe déjà avec cette adresse email. Veuillez rééssayer.");
            return;
        }

        if ($in['password_confirm'] != $in['password']) {
            $this->_errorRetourApi('/register/', "Veuillez saisir les mêmes mots de passe.");
            return;
        }

        $in['password'] = Security::hash($in['password'], 'sha1', true);

        // Save user
        $entity = $users_table->newEntity($in);
        if (!$users_table->save($entity)) {
            $this->_errorRetourApi('/register/', "Erreur lors de l'enregistrement.");
            return;
        }
        $this->data['returns']['user'] = json_encode($entity);
        die(json_encode($this->data));
    }

// Création / connexion utilisateur google
    public
    function registerGoogle()
    {
        $in = $this->request->data;     // En POST

        $client = new Google_Client(['client_id' => self::GOOGLE_OAUTH_CLIENT_ID]);
        $payload = $client->verifyIdToken($in['id_token']);

        $data = array();
        $data['email'] = $payload['email'];
        $data['firstname'] = $payload['given_name'];
        $data['lastname'] = $payload['family_name'];
        $data['avatar'] = $payload['picture'];
        $data['social_id'] = self::SOCIAL_ID_GOOGLE;
        $users_table = TableRegistry::get('users');

        $user_exist = $users_table->find('all')
            ->where(array('email' => $payload['email'], 'social_id' => self::SOCIAL_ID_GOOGLE))
            ->first();

        // Si l'utilisateur google est déjà enregistré, on renvoi les infos de l'user, sinon on le crée en base
        if (!empty($user_exist)) {
            $this->data['returns']['user'] = json_encode($user_exist);
        } else {
            $entity = $users_table->newEntity($data);
            $save_user = $users_table->save($entity);
            if ($save_user) {
                $this->data['returns']['user'] = json_encode($entity);
            } else {
                $this->_errorRetourApi('/registerGoogle/', "Erreur d'ajout de l'utilisateur.");
                return;
            }
        }

        die(json_encode($this->data));
    }

// ----------------------------------------------------------------------------------------------------------------
// RETOURS ERREURS

    private
    function _notAuthenticated()
    {
        $this->data['return_code'] = self::RETURN_CODE_ERROR_AUTH;
        $this->data['error'] = "Echec lors de l'authentification";
        die(json_encode($this->data));
    }

    private
    function _errorParameter($missing_param)
    {
        $this->data['return_code'] = self::RETURN_CODE_ERROR_MISSING_PARAMETER;
        $this->data['error'] = "Paramètre '$missing_param' manquant";
        die(json_encode($this->data));
    }

    private
    function _errorRetourApi($url_api, $error_supp = "")
    {
        $this->data['return_code'] = self::RETURN_CODE_ERROR_RETOUR_API;
        $this->data['error'] = "L'API n'a retourné aucune données, ou une donnée non valide. (URL : " . $url_api . "). " . ((!empty($error_supp)) ? "Infos erreur supp : " . $error_supp : "");
        die(json_encode($this->data));
    }

}