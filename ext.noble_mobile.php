<?php

	include 'includes/mobile_detect.php';

	class Noble_mobile_ext {

		public $settings = array();
		public $name = 'Noble Mobile';
		public $version = '1.0';
		public $description = 'Detects mobile browsers and loads mobile templates accordingly.';
		public $settings_exist = 'n';
		public $docs_url = '';

		private $_mobile_detector = null;
		private $_is_mobile = null;
		private $_site_pages = null;
		private $_cookie = null;
		private $_cookie_timeout = 2678400;

		/**
		 * Constructor
		 *
		 * @param mixed Settings array or empty string if none exist.
		 */
		public function __construct($settings='') {
			$this->EE =& get_instance();
			$this->settings = $settings;
		}

		/**
		 * Activate Extension
		 *
		 * This function enters the extension into the exp_extensions table
		 *
		 * @see http://codeigniter.com/user_guide/database/index.html for more information on the db class.
		 *
		 * @return void
		 */
		public function activate_extension() {
			$hooks = array(
				'core_template_route' => 'core_template_route'
			);
			foreach($hooks as $hook => $method) {
				$data = array(
					'class' => __CLASS__,
					'method' => $method,
					'hook' => $hook,
					'priority' => 10,
					'version' => $this->version,
					'enabled' => 'y',
					'settings' => ''
				);
				$this->EE->db->insert('exp_extensions', $data);
			}
			return true;
		}

		/**
		 * Update Extension
		 *
		 * This function performs any necessary db updates when the extension page is visited.
		 *
		 * @return mixed void on update / false if none
		 */
		public function update_extension($current = '') {
			if($current == '' || $current == $this->version) {
				return FALSE;
			}
			if($current < '1.0') {
				// Update to version 1.0
			}
			$this->EE->db->where('class', __CLASS__);
			$this->EE->db->update(
				'extensions',
				array('version' => $this->version)
			);
		}

		/**
		 * Disable Extension
		 *
		 * This method removes information from the exp_extensions table
		 *
		 * @return void
		 */
		public function disable_extension() {
			$this->EE->db->where('class', __CLASS__);
			$this->EE->db->delete('extensions');
		}

		public function core_template_route($uri) {
			$this->_check_for_switch();
			$this->_load_mobile_detector();
			$this->_set_class_variables();
			$this->_set_global_variables();
			if($this->_display_mobile()) {
				$this->_get_site_pages();
				$page_id = $this->_get_page_id($uri);
				if($page_id === false) {
					$current_template = $this->_get_template_from_segments();
				} else {
					$template_id = $this->_get_template_id($page_id);
					$current_template = $this->_get_template_from_id($template_id);
				}
				$mobile_template = $this->_get_mobile_template($current_template);
				if($mobile_template !== false) {
					return $mobile_template;
				} else {
					return;
				}
			}
		}

		private function _check_for_switch() {
			$switch_to = $this->EE->input->get('switch_to');
			if($switch_to) {
				$this->_set_cookie($switch_to);
				$this->EE->functions->redirect($this->EE->functions->fetch_current_uri());
			}
		}

		private function _load_mobile_detector() {
			$this->_mobile_detector = new Mobile_Detect();
		}

		private function _set_class_variables() {
			$this->_is_mobile = $this->_mobile_detector->isMobile();
			$this->_cookie = $this->_get_cookie();
		}

		private function _set_global_variables() {
			$this->EE->config->_global_vars['is_mobile'] = $this->_is_mobile;
			$this->EE->config->_global_vars['is_desktop'] = !$this->_is_mobile;
			$current_uri = $this->EE->functions->fetch_current_uri();
			$this->EE->config->_global_vars['noble_mobile:switch_to_mobile'] = $current_uri.'?switch_to=mobile';
			$this->EE->config->_global_vars['noble_mobile:switch_to_desktop'] = $current_uri.'?switch_to=desktop';
		}

		private function _display_mobile() {
			if($this->_is_mobile && $this->_cookie != 'desktop') {
				return true;
			}
			else if(!$this->_is_mobile && $this->_cookie == 'mobile') {
				return true;
			}
			else {
				return false;
			}
		}

		private function _get_cookie() {
			return $this->EE->input->cookie(strtolower(__CLASS__));
		}

		private function _set_cookie($value) {
			$this->EE->functions->set_cookie(strtolower(__CLASS__), $value, $this->_cookie_timeout);
		}

		private function _get_site_pages() {
			$site_id = $this->_get_site_id();
			$site_pages = $this->EE->config->item('site_pages');
			$this->_site_pages = $site_pages[$site_id];
		}

		private function _get_site_id() {
			return $this->EE->config->item('site_id');
		}

		private function _get_page_id($uri) {
			$uri = $this->_trim_uri($uri);
			foreach($this->_site_pages['uris'] as $key => $value) {
				if($this->_trim_uri($value) == $uri) {
					return $key;
				}
			}
			return false;
		}

		private function _trim_uri($uri) {
			$uri = trim($uri, '/');
			$uri = rtrim($uri, '/');
			return $uri;
		}

		private function _get_template_id($page_id) {
			return $this->_site_pages['templates'][$page_id];
		}

		private function _get_template_from_id($template_id) {
			$sql = "
				SELECT templates.template_name AS template_name, template_groups.group_name AS group_name
				FROM exp_templates AS templates,
				exp_template_groups AS template_groups
				WHERE templates.template_id = '%d'
				AND templates.group_id = template_groups.group_id
			";
			$sql = sprintf($sql, $template_id);
			$results = $this->EE->db->query($sql);
			if($results->num_rows() == 1) {
				$results_array = $results->result_array();
				$row = $results_array[0];
				return $row;
			} else {
				return false;
			}
		}

		private function _get_template_from_segments() {
			$segment_1 = $this->EE->uri->segment(1);
			$segment_2 = $this->EE->uri->segment(2);
			if($segment_1 == '' && $segment_2 == '') {
				$segment_1 = $this->_get_homepage_template_group();
				$segment_2 = 'index';
			}
			if($segment_1 != '' && $segment_2 == '') {
				$segment_2 = 'index';
			} else if($segment_1 != '' && $segment_2 != '') {
				if(!$this->_template_exists($segment_1, $segment_2)) {
					$segment_2 = 'index';
				}
			}
			return array('group_name' => $segment_1, 'template_name' => $segment_2);
		}

		private function _get_mobile_template($current_template) {
			$template_name = $current_template['template_name'];
			$template_group = 'mobile__' . $current_template['group_name'];
			$sql = "
				SELECT templates.template_name AS template_name, template_groups.group_name AS group_name
				FROM exp_templates AS templates,
				exp_template_groups AS template_groups
				WHERE template_name = '%s'
				AND group_name = '%s'
				AND templates.group_id = template_groups.group_id
			";
			$sql = sprintf($sql, $template_name, $template_group);
			$results = $this->EE->db->query($sql);
			if($results->num_rows() > 0) {
				return array($template_group, $template_name);
			} else {
				return false;
			}
		}

		private function _get_homepage_template_group() {
			$sql = "
				SELECT template_groups.group_name AS group_name
				FROM exp_template_groups AS template_groups
				WHERE template_groups.is_site_default = 'y'
			";
			$results = $this->EE->db->query($sql);
			if($results->num_rows() == 1) {
				$results_array = $results->result_array();
				$row = $results_array[0];
				return $row['group_name'];
			} else {
				return false;
			}
		}

		private function _template_exists($template_group, $template_name) {
			$sql = "
				SELECT templates.template_name AS template_name, template_groups.group_name AS group_name
				FROM exp_templates AS templates,
				exp_template_groups AS template_groups
				WHERE template_name = '%s'
				AND group_name = '%s'
				AND templates.group_id = template_groups.group_id
			";
			$sql = sprintf($sql, $template_name, $template_group);
			$results = $this->EE->db->query($sql);
			if($results->num_rows() > 0) {
				return true;
			} else {
				return false;
			}
		}

	}

?>
