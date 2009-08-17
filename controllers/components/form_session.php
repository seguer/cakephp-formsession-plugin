<?php

/**
* FormSessionComponent
* Provides the ability to save Form data in a Session var and retrieve it later in another action
* and/or controller
* 
* @copyright    Copyright 2009, Seguer (http://withoutboundary.com.au/)
* @link         http://withoutboundary.com.au/
* @author       Seguer <http://withoutboundary.com.au/>
* @version      1.0.090814
* @license      http://www.opensource.org/licenses/mit-license.php The MIT License
* @credits      http://www.jamesfairhurst.co.uk/posts/view/validating_cakephp_data_from_another_model/
*/
class FormSessionComponent extends Object {
	
	/**
	* FormSession needs the core Session component to save/read stuff
	* 
	* @var mixed
	* @access protected
	*/
	var $components = array('Session');
	
	/**
	* FormSession requires a controller
	* 
	* @var object
	* @access protected
	*/
	var $controller = null;
	
	/**
	* Default settings array with blank model
	* model['name'] is the name of the Model
	* model['path'] is the path of the Model from the Controller through ->
	* 
	* @access public
	* @var mixed
	* @access protected
	*/
	var $settings = array(
		'model' => array(
			'name' => null,
			'path' => null
		)
	);

	/**
	* Initializes the component and gets the reference to the Controller.
	* It also checks the settings param for a model name and attempts to
	* find that model in the Controller or other models
	* 
	* @param mixed $controller
	* @param mixed $settings
	* @return void
	*/
	function initialize(&$controller, $settings = array())
	{
		$this->controller =& $controller;
		
		if (!array_key_exists('model', $settings))
		{
			//they haven't set the model manually
			$this->settings['model'] = $this->_determineDefaultModel();
		}
		else
		{
			$this->settings['model'] = $this->_determineModelChaining($settings['model']);
			if ($this->settings['model'] == null)
			{
				//couldn't find target model
				die('FormSession couldn\'t find the target model: ' . $settings['model'] . '. Make sure that it is in the controller, or that the loaded models have a relationship (direct or indirect) to that model.');
			}
		}
		//debug($this->settings);
		//die();
	}
	
	/**
	* Used to write data and validation errors off the default
	* or chosen model to Session
	* 
	* In your controller:
	* $this->FormSession->write();
	* or
	* $this->FormSession->write('Comment');
	* 
	* @access public
	* @param mixed $model If left empty it will use the model already defaulted in the Component
	* @return boolean success
	*/
	function write($model = null)
	{
		$model = $this->_determineModel($model);
		
		if ($model != null)
		{
			return ($this->Session->write('FormSession.' . $model['name'] . '.data', $this->controller->data) &&
							$this->Session->write('FormSession.' . $model['name'] . '.errors', eval('return $this->controller->' . $this->_join_path_model($model['path'], $model['name']) . '->validationErrors;')));
		}
		return false;
	}
	
	/**
	* Used to read data and validation errors off the default
	* or chosen model from Session into that Model
	* 
	* In your controller:
	* $this->FormSession->read();
	* or
	* $this->FormSession->read('Comment');
	* 
	* @access public
	* @param mixed $model
	* @param boolean $delete Whether to delete the Session data after reading (works like a flash then)
	* @return boolean success
	*/
	function read($model = null, $delete = true)
	{
		$model = $this->_determineModel($model);
		
		if ($model != null)
		{
			if ($this->Session->check('FormSession.' . $model['name']))
			{
				$formSessionData = $this->Session->read('FormSession.' . $model['name'] . '.data');
				$formSessionErrors = $this->Session->read('FormSession.' . $model['name'] . '.errors');
				$this->controller->data[$model['name']] = $formSessionData[$model['name']];
				eval('$this->controller->' . $this->_join_path_model($model['path'], $model['name']) . '->validationErrors = $formSessionErrors;');
				if ($delete)
				{
					$this->delete($model['name']);
				}
				return true;
			}
		}
		return false;
	}
	
	/**
	* Deletes Session data for a Model name
	* 
	* In your controller:
	* $this->FormSession->delete();
	* or
	* $this->FormSession->delete('Comment');
	* 
	* @access public
	* @param mixed $model
	*/
	function delete($model = null)
	{
		$model = $this->_determineModel($model);
		
		if ($model != null)
		{
			return $this->Session->delete('FormSession.' . Inflector::classify($model['name']));
		}
		return false;
	}
	
	/**
	* Attempts to determine the Model to be used from a public method
	* If model is null, it uses the defaulted Model, otherwise it will
	* attempt to find the model wherever it can
	* 
	* @access protected
	* @param mixed $model
	* @return mixed Model or null
	*/
	function _determineModel($model)
	{
		if ($model == null)
		{
			//have model from default setup
			return $this->settings['model'];
		}
		
		//otherwise $model is our target
		return $this->_determineModelChaining($model);
	}
	
	/**
	* Gets the default model from a Controller
	* 
	* @access protected
	* @return mixed Model or null
	*/
	function _determineDefaultModel()
	{
		if ($this->controller != null)
		{
			//modelClass is always a value, even if $uses is null, or an empty array
			//if $uses is an array, it uses the first value - which could be diff to the controller name!
			return $this->_createModel(Inflector::classify($this->controller->modelClass));
		}
		return null;
	}
	
	/**
	* Attempts to find the Model directly on the Controller, otherwise
	* it will loop through all the loaded Models on the Controller to
	* see if it can find it from a loaded Model
	* 
	* @access protected
	* @param mixed $targetModel
	* @return mixed Model or null
	*/
	function _determineModelChaining($targetModel)
	{
		if (in_array($targetModel, $this->controller->modelNames))
		{
			//no path, no chaining, already on controller
			return $this->_createModel($targetModel);
		}
		
		//use model::tableToModel instead of all the diff types of relations
		//it returns an array where key is the table name and value is the model name (model => Model)
		$model = null;
		foreach ($this->controller->modelNames as $controllerModel)
		{
			//foreach model in the controller, query its tableToModel for our target
			//if it doesn't find the target, loop through each of those models recursively
			//until we find our target or run out of related
			//note: build the path as we traverse!
			if (in_array($targetModel, $this->controller->{$controllerModel}->tableToModel))
			{
				//debug('Found target in controllerModel: ' . $controllerModel);
				$model = $this->_createModel($targetModel, $controllerModel);
				break;
			}
			else
			{
				//we have to recursively search
				//debug('Starting a recurse cycle on controllerModel: ' . $controllerModel);
				$model = $this->_findModelChainFromModel($targetModel, $controllerModel, '');
				if ($model != null)
				{
					break;
				}
			}
		}
		return $model;
	}
	
	/**
	* Recrusive method that looks on a search model for the target model. If the target is not
	* found, it will recurse into any models that are on the search model
	* 
	* @access protected
	* @param mixed $targetModel The Model name we are looking for
	* @param mixed $searchModel The searchModel name we are looking in
	* @param mixed $path The path to get to searchModel from the controller
	*/
	function _findModelChainFromModel($targetModel, $searchModel, $path)
	{
		//need to eval to chain "->"'s
		//in $searchModel, check its tableToModel
		$tableToModel = array();
		
		if ($path == '')
		{
			//debug('Path was blank');
			//debug('Getting tableToModel from eval: ' . 'return $this->controller->'.$searchModel.'->tableToModel;');
			$tableToModel = eval('return $this->controller->'.$searchModel.'->tableToModel;');
		}
		else
		{
			//debug('Path was: ' . $path);
			//debug('Getting tableToModel from eval: ' . 'return $this->controller->'.$path.'->'.$searchModel.'->tableToModel;');
			$tableToModel = eval('return $this->controller->'.$path.'->'.$searchModel.'->tableToModel;');
		}
		
		if (empty($tableToModel))
		{
			//exit this function, this searchModel is a dead end
			return null;
		}
		
		//debug('tableToModel is: ' . print_r($tableToModel, true));
		
		$model = null;
		$newPath = '';
		if ($path == '')
		{
			$newPath = $searchModel;
		}
		else
		{
			$newPath = $path.'->'.$searchModel;
		}
		
		if (in_array($targetModel, $tableToModel))
		{
			//found target
			//debug('Found target model in searchModel: ' . $searchModel);
			$model = $this->_createModel($targetModel, $newPath);
		}
		else
		{
			//else recurse
			foreach ($tableToModel as $recurseSearchModel)
			{
				//debug('Checking recurseSearchModel: ' . $recurseSearchModel);
				//check we haven't done that model
				if ($path == $recurseSearchModel ||
						substr($path, strlen($path) - strlen($recurseSearchModel) == $recurseSearchModel) ||
						strstr($path, $recurseSearchModel) != false)
				{
					//path is, ends with, or contains recurseSearchModel, so we came from there, don't do anything!
					//debug('Found recurseSearchModel (' . $recurseSearchModel . ') in path (' . $path . ')');
					break;
				}
				
				//if we're not going to go back into this model
				if ($newPath != $recurseSearchModel)
				{
					//debug('Recursing again into recurseSearchModel (' . $recurseSearchModel . ') with path: ' . $newPath);
					$model = $this->_findModelChainFromModel($targetModel, $recurseSearchModel, $newPath);
					if ($model != null)
					{
						break;
					}
				}
			}
		}
		return $model;
	}
	
	/**
	* Creates a Model data structure from a given name and path
	* 
	* @access protected
	* @param mixed $name
	* @param mixed $path
	* @return mixed Model
	*/
	function _createModel($name = null, $path = '')
	{
		$model = array();
		$model['name'] = $name;
		$model['path'] = $path;
		return $model;
	}
	
	/**
	* Joins a path and a model properly ("->" on the insides only)
	* 
	* @access protected
	* @param string $path
	* @param mixed $model
	* @return string Joined path
	*/
	function _join_path_model($path, $model)
	{
		if ($path == '')
		{
			return $model;
		}
		else
		{
			return $path.'->'.$model;
		}
	}
}