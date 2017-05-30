<?php

namespace MakeWeb\WPPluginUpdater;

class Client
{
    private $apiEndpoint;
    private $product_id;
    private $product_name;
    private $type;
    private $plugin_file;
    private $plugin_email;
    private $plugin_key;

    public function __construct(
        $product_id,
        $product_name,
        $plugin_file = '',
        $plugin_email,
        $plugin_key
    )
    {
        // Store setup data
        $this->product_id = $product_id;
        $this->product_name = $product_name;
        $this->apiEndpoint = 'http://updater.makeweb.com.au/api/license-manager/v1';
        $this->plugin_file = $plugin_file;
        $this->licence_email = $plugin_email;
        $this->licence_key = $plugin_key;
        if (is_admin()) {
            add_filter('pre_set_site_transient_update_plugins', array($this, 'checkForUpdate'));
            add_filter('plugins_api', array($this, 'plugins_api_handler'), 10, 3 );
        }
    }

    /**
     * Check to see if an update is available for the plugin
     **/
    public function checkForUpdate($transient)
    {
        if (empty($transient->checked)) {
            return $transient;
        }

        $info = $this->updateIsAvailable();
        if ($info !== false) {

            // Plugin update
            $plugin_slug = plugin_basename($this->plugin_file);

            $transient->response[$plugin_slug] = (object)array(
                'new_version' => $info->version,
                'package'     => $info->package_url,
                'slug'        => $plugin_slug
           );
        }

        return $transient;
    }

    public function updateIsAvailable()
    {
        $licenseInfo = $this->getLicenseInfo();

        // If fetching the license info returns an API error we can assume no update is required
        if ($this->isApiError($licenseInfo)) {
            return false;
        }

        if (version_compare($licenseInfo->version, $this->getLocalVersion(), '>')) {
            return $licenseInfo;
        }

        return false;
    }

    public function getLicenseInfo()
    {
        $info = $this->callApi(
            'info',  array('p' => $this->product_id, 'e' => $this->licence_email, 'l' => $this->licence_key)
       );

        return $info;
    }

    public function plugins_api_handler($res, $action, $args)
    {
        if ($action == 'plugin_information') {

            // If the request is for this plugin, respond to it
            if (isset($args->slug)&& $args->slug == plugin_basename($this->plugin_file ) ) {
                $info = $this->getLicenseInfo();

                $res = (object)array(
                    'name'          => isset($info->name)? $info->name : '',
                    'version'       => $info->version,
                    'slug'          => $args->slug,
                    'download_link' => $info->package_url,

                    'tested'        => isset($info->tested)? $info->tested : '',
                    'requires'      => isset($info->requires)? $info->requires : '',
                    'last_updated'  => isset($info->last_updated)? $info->last_updated : '',
                    'homepage'      => isset($info->description_url)? $info->description_url : '',

                    'sections'      => array(
                        'description' => $info->description,
                   ),

                    'banners'       => array(
                        'low'  => isset($info->banner_low)? $info->banner_low : '',
                        'high' => isset($info->banner_high)? $info->banner_high : ''
                   ),
                    'external'      => true
               );

                // Add change log tab if the server sent it
                if (isset($info->changelog)) {
                    $res['sections']['changelog'] = $info->changelog;
                }
                return $res;
            }
        }

        // Not our request, let WordPress handle this.
        return false;
    }

    // HELPER FUNCTIONS FOR ACCESSING PROPERTIES
    private function getLocalVersion()
    {
        $plugin_data = get_plugin_data($this->plugin_file, false);
        return $plugin_data['Version'];
    }

    // API HELPER FUNCTIONS
    private function callApi($action, $params)
    {
        $url = $this->apiEndpoint . '/' . $action;

        // Append parameters for GET request
        $url .= '?' . http_build_query($params);

        // Send the request
        $response = wp_remote_get($url);
        if (is_wp_error($response)) {
            return false;
        }

        $response_body = wp_remote_retrieve_body($response);
        $result = json_decode($response_body);

        return $result;
    }

    private function isApiError($response)
    {
        if ($response === false) {
            return true;
        }

        if (!is_object($response)) {
            return true;
        }

        if (isset($response->error)) {
            return true;
        }

        return false;
    }
}
