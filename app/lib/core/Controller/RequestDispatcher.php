<?php
/** ---------------------------------------------------------------------
 * app/lib/core/Controller/RequestDispatcher.php :
 * ----------------------------------------------------------------------
 * CollectiveAccess
 * Open-source collections management software
 * ----------------------------------------------------------------------
 *
 * Software by Whirl-i-Gig (http://www.whirl-i-gig.com)
 * Copyright 2007-2016 Whirl-i-Gig
 *
 * For more information visit http://www.CollectiveAccess.org
 *
 * This program is free software; you may redistribute it and/or modify it under
 * the terms of the provided license as published by Whirl-i-Gig
 *
 * CollectiveAccess is distributed in the hope that it will be useful, but
 * WITHOUT ANY WARRANTIES whatsoever, including any implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 *
 * This source code is free and modifiable under the terms of
 * GNU General Public License. (http://www.gnu.org/copyleft/gpl.html). See
 * the "license.txt" file for details, or visit the CollectiveAccess web site at
 * http://www.CollectiveAccess.org
 *
 * @package CollectiveAccess
 * @subpackage Core
 * @license http://www.gnu.org/copyleft/gpl.html GNU Public License version 3
 *
 * ----------------------------------------------------------------------
 */

 /**
  *
  */

require_once(__CA_LIB_DIR__."/core/BaseObject.php");
require_once(__CA_LIB_DIR__."/core/ApplicationError.php");
require_once(__CA_LIB_DIR__."/core/Controller/Request/RequestHTTP.php");
require_once(__CA_LIB_DIR__."/core/Controller/Response/ResponseHTTP.php");
require_once(__CA_LIB_DIR__."/core/AccessRestrictions.php");
require_once(__CA_LIB_DIR__."/ca/ApplicationPluginManager.php");

class RequestDispatcher extends BaseObject {
	# -------------------------------------------------------
	private $opo_request;
	private $opo_response;

	private $ops_controller_path;
	private $ops_application_plugins_path;
	private $ops_theme_plugins_path;

	private $opa_module_path;
	private $ops_controller;
	private $ops_action;
	private $ops_action_extra;
	private $opb_is_dispatchable = false;

	private $opa_plugins = null;

	private $ops_default_action;
	# -------------------------------------------------------
	public function __construct($po_request=null, $po_response=null) {
		parent::__construct();

		if ($po_request) {
			$this->setRequest($po_request);
		}
		if ($po_response) {
			$this->setResponse($po_response);
		}
	}
	# -------------------------------------------------------
	public function setRequest(&$po_request) {
		$this->opo_request = $po_request;

		switch($po_request->getScriptName()){
			case "service.php":
				$this->ops_controller_path = $this->opo_request->config->get('service_controllers_directory');
				$this->ops_default_action = $this->opo_request->config->get('service_default_action');
				break;
			case "index.php":
			default:
				$this->ops_controller_path = $this->opo_request->config->get('controllers_directory');
				$this->ops_default_action = $this->opo_request->config->get('default_action');
				break;
		}

		$this->ops_application_plugins_path = $this->opo_request->config->get('application_plugins');
		$this->ops_theme_plugins_path = __CA_THEME_DIR__."/plugins";

		$this->parseRequest();
	}
	# -------------------------------------------------------
	public function setResponse(&$po_response) {
		$this->opo_response = $po_response;
	}
	# -------------------------------------------------------
	private function parseRequest() {
		if (!($vs_path = $this->opo_request->getPathInfo()) || ($vs_path == '/')) {
			$vs_path = $this->ops_default_action;
		}

		if ($vs_path{0} === '/') { $vs_path = substr($vs_path, 1); }	// trim leading forward slash...
		$va_tmp = explode('/', $vs_path);								// break path into parts

		if (is_dir($this->ops_theme_plugins_path.'/'.$va_tmp[0].'/controllers')) {
			// is theme plugin
			$vs_controller_path = $this->ops_theme_plugins_path.'/'.$va_tmp[0].'/controllers';

			$va_module_path = array();
			$vs_module_path_prefix = $va_tmp[0].'/controllers';
			array_shift($va_tmp);

			$this->opo_request->setIsApplicationPlugin(true);
		} elseif (is_dir($this->ops_application_plugins_path.'/'.$va_tmp[0].'/controllers')) {
			// is application plugin
			$vs_controller_path = $this->ops_application_plugins_path.'/'.$va_tmp[0].'/controllers';

			$va_module_path = array();
			$vs_module_path_prefix = $va_tmp[0].'/controllers';
			array_shift($va_tmp);

			$this->opo_request->setIsApplicationPlugin(true);
		} else {
			$vs_controller_path = $this->ops_controller_path;
			$va_module_path = array();
			$vs_module_path_prefix = '';
		}
		while(sizeof($va_tmp)) {
			$vs_path_element = array_shift($va_tmp);
			if (!$vs_path_element) { continue; }
			$va_module_path[] = $vs_path_element;
			if (!is_dir($vs_controller_path.'/'.join('/', $va_module_path))) {
				array_unshift($va_tmp, array_pop($va_module_path));
				break;
			}
		}
		$this->opa_module_path =& $va_module_path;
		if ($vs_module_path_prefix) { array_unshift($this->opa_module_path, $vs_module_path_prefix); }
		$this->ops_controller = ucfirst(array_shift($va_tmp));
		$this->ops_action = array_shift($va_tmp);
		if ((sizeof($va_tmp) % 2) != 0) {
			$this->ops_action_extra = array_shift($va_tmp);
		} else {
			$this->ops_action_extra = '';
		}

		while(sizeof($va_tmp) > 0) {
			$this->opo_request->setParameter(array_shift($va_tmp), array_shift($va_tmp), 'PATH');
		}
		$this->opo_request->setModulePath(join('/', $this->opa_module_path));
		$this->opo_request->setController($this->ops_controller);
		$this->opo_request->setAction($this->ops_action);
		$this->opo_request->setActionExtra($this->ops_action_extra);

		$this->opo_request->setControllerUrl(preg_replace("![/]+!", "/", join('/', array_merge(array($this->opo_request->getBaseUrlPath(), $this->opo_request->getScriptName()), array($this->opo_request->getModulePath()), array($this->ops_controller)))));

		if ($this->ops_controller != '') {
			return $this->opb_is_dispatchable = true;
		}
		$this->postError(2310, _t("Not dispatchable"), "RequestDispatcher->parseRequest()");
		return $this->opb_is_dispatchable = false;
	}
	# -------------------------------------------------------
	public function setPlugins($pa_plugins) {
		$this->opa_plugins = $pa_plugins;
	}
	# -------------------------------------------------------
	public function getPlugins() {
		return $this->opa_plugins;
	}
	# -------------------------------------------------------
	public function dispatch($pa_plugins) {
		$this->setPlugins($pa_plugins);
		if ($this->isDispatchable()) {
			do {
				$vs_classname = ucfirst($this->ops_controller).'Controller';

				// first check for controller in theme...
				if (!defined('__CA_THEME_DIR__') || !file_exists(__CA_THEME_DIR__.'/controllers/'.join('/', $this->opa_module_path).'/'.$vs_classname.'.php') || !include_once(__CA_THEME_DIR__.'/controllers/'.join('/', $this->opa_module_path).'/'.$vs_classname.'.php')) {
					// then check controllers directory...
					if (!file_exists($this->ops_controller_path.'/'.join('/', $this->opa_module_path).'/'.$vs_classname.'.php') || !include_once($this->ops_controller_path.'/'.join('/', $this->opa_module_path).'/'.$vs_classname.'.php')) {
						// ... next check theme plugins
						if (!file_exists($this->ops_theme_plugins_path.'/'.join('/', $this->opa_module_path).'/'.$vs_classname.'.php') || !include_once($this->ops_theme_plugins_path.'/'.join('/', $this->opa_module_path).'/'.$vs_classname.'.php')) {
							// ... next check application plugins
							if (!file_exists($this->ops_application_plugins_path.'/'.join('/', $this->opa_module_path).'/'.$vs_classname.'.php') || !include_once($this->ops_application_plugins_path.'/'.join('/', $this->opa_module_path).'/'.$vs_classname.'.php')) {

								// ... next check the generic "_root_" plugin directory
								// plugins in here act as if they are in the app/controllers directory
								if (!file_exists($this->ops_application_plugins_path.'/_root_/controllers/'.join('/', $this->opa_module_path).'/'.$vs_classname.'.php') || !include_once($this->ops_application_plugins_path.'/_root_/controllers/'.join('/', $this->opa_module_path).'/'.$vs_classname.'.php')) {
									// ... next check for root controllers in plugins
									$o_app_plugin_manager = new ApplicationPluginManager();
									$va_app_plugin_names = $o_app_plugin_manager->getPluginNames();

									$vb_is_error = true;
									foreach($va_app_plugin_names as $vs_app_plugin_name) {
										if ($vs_app_plugin_name === '_root_') { continue; }
										if (file_exists($this->ops_application_plugins_path.'/'.$vs_app_plugin_name.'/controllers/_root_/'.$vs_classname.'.php') && include_once($this->ops_application_plugins_path.'/'.$vs_app_plugin_name.'/controllers/_root_/'.$vs_classname.'.php')) {
											$vb_is_error = false;
										}
									}

									if ($vb_is_error) {
										// Try to load "Default" controller in controllers directory and call method with controller name
										if (file_exists($this->ops_controller_path.'/DefaultController.php') && @include_once($this->ops_controller_path.'/DefaultController.php')) {

											$vs_default_method = $this->ops_controller;

											// Set DefaultController as controller class
											$vs_classname = 'DefaultController';

											// Take rest of path and pass as params to DefaultController __call()
											$va_params = array($this->ops_action);
											if ($this->ops_action_extra) { $va_params[] = $this->ops_action_extra; }

											$va_path_params = $this->opo_request->getParameters(array('PATH'));
											foreach($va_path_params as $vs_param => $vs_value) {
												if (!$vs_param) { $va_params[] = $vs_param; }
												if (!$vs_value) { $va_params[] = $vs_value; }
											}
											$this->ops_action = $vs_default_method;
										} else {
											// Invalid controller path
											$this->postError(2300, _t("Invalid controller path"), "RequestDispatcher->dispatch()");
											return false;
										}
									}
								}
							}
						}
					}
				}

				if(!$this->opo_request->user->canAccess($this->opa_module_path, $this->ops_controller, $this->ops_action)){
					switch($this->opo_request->getScriptName()){
						case "service.php":
							// service auth requests for deprecated service API are allowed to go through to
							// dispatch because in that case logging in requires running actual controller code.
							// this is bad practice and should be removed once the old API is no longer supported.
							if(!$this->opo_request->isServiceAuthRequest()) {
								$this->opo_response->setHTTPResponseCode(401,_t("Access denied"));
								$this->opo_response->addHeader('WWW-Authenticate','Basic realm="CollectiveAccess Service API"');
								return true; // this is kinda stupid but otherwise the "error redirect" code of AppController kicks in, which is not what we want here!
							}
							break;
						case "index.php":
						default:
							$this->postError(2320, _t("Access denied"), "RequestDispatcher->dispatch()");
							return false;
					}
				}


				$o_action_controller = new $vs_classname($this->opo_request, $this->opo_response, $this->opo_request->getViewsDirectoryPath().'/'.join('/', $this->opa_module_path));

				$this->opo_request->setIsDispatched(true);

				if (!$this->opo_response->isRedirect()) {
					$vb_plugin_cancelled_dispatch = false;
					foreach($pa_plugins as $vo_plugin) {
						$va_ret = $vo_plugin->preDispatch();
						if (is_array($va_ret) && isset($va_ret['dont_dispatch']) && $va_ret['dont_dispatch']) {
							$vb_plugin_cancelled_dispatch = true;
						}
					}

					if (!$vb_plugin_cancelled_dispatch) {
						if (!$this->ops_action || !(method_exists($o_action_controller, $this->ops_action) || method_exists($o_action_controller, '__call'))) {
							$this->postError(2310, _t("Not dispatchable"), "RequestDispatcher->dispatch()");
							return false;
						}
						$o_action_controller->{$this->ops_action}($va_params);
						if ($o_action_controller->numErrors()) {
							$this->errors = $o_action_controller->errors();
							return false;
						}
					}

					// reload plugins in case controller we dispatched to has changed them
					$pa_plugins = $this->getPlugins($pa_plugins);

					foreach($pa_plugins as $vo_plugin) {
						$vo_plugin->postDispatch();
					}

					if (!$this->opo_request->isDispatched()) {
						$this->parseRequest();
					}
				}
			} while($this->opo_request->isDispatched() == false);

			return true;
		}
		$this->postError(2310, _t("Not dispatchable"), "RequestDispatcher->dispatch()");
		return false;
	}
	# -------------------------------------------------------
	public function isDispatchable() {
		return $this->opb_is_dispatchable;
	}
	# -------------------------------------------------------
}
