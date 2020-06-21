<?php
namespace topshelfcraft\tracker;

use Craft;
use craft\base\Plugin as BasePlugin;
use craft\helpers\UrlHelper;
use craft\web\twig\variables\CraftVariable;
use topshelfcraft\ranger\Plugin;
use topshelfcraft\tracker\config\Settings;
use yii\base\Event;

/**
 * @author Michael Rog <michael@michaelrog.com>
 * @package Tracker
 * @since 3.0.0
 *
 * @method Settings getSettings()
 */
class Tracker extends BasePlugin
{

	/**
	 * @inheritdoc
	 */
	public function init()
	{

		parent::init();
		Plugin::watch($this);

		Event::on(
			CraftVariable::class,
			CraftVariable::EVENT_INIT,
			function (Event $event) {
				/** @var CraftVariable $variable **/
				$variable = $event->sender;
				$variable->set('tracker', $this);
			}
		);

	}

	/**
	 * Creates and returns the model used to store the plugin’s settings.
	 *
	 * @return Settings|null
	 */
	protected function createSettingsModel()
	{
		return new Settings();
	}

	/**
	 * Consumes an array of user-provided parameters,
	 * merges the user-provided parameters with several layers of pre-defined default parameters,
	 * and returns the combined list (with Google-style param handles).
	 *
	 * @param $params array The list of user-provided parameters for this tracker request
	 *
	 * @return array The assembled list of parameters, with Google-style param keys, for this request.
	 *
	 * @throws \yii\base\InvalidConfigException if Request doesn't exist.
	 */
	public function getTrackerParams(array $params = [])
	{

		$settings = $this->getSettings();

		// TODO: Change CID param to use GA's actual Client ID number (from cookie?)

		$defaults = [
			'location' => Craft::$app->request->getUrl(),
			'clientId' => Craft::$app->getUser()->getId() ?? 0,
			'type' => 'pageview',
			'trackingId' => $settings->trackingId,
			'version' => '1',
		];

		// Instantiate our new params array with the bare minimum

		$assembledParams = static::googleizeParams($defaults);

		// Merge in any general site defaults that are set in the plugin config file.

		$assembledParams = array_merge($assembledParams, static::googleizeParams($settings->defaultParams));

		// Merge in any environment defaults that are set in the plugin config file.

		$assembledParams = array_merge($assembledParams, static::googleizeParams($settings->environmentParams));

		// Merge in any user-provided params for this request

		$assembledParams = array_merge($assembledParams, static::googleizeParams($params));

		// Return the final assembled list

		return $assembledParams;

	}

	/**
	 * Consumes an array of parameters and, for any parameter handle that is defined in $googleParamNames,
	 * swaps the friendly-style key with a Google-style parameter handle.
	 *
	 * @param array $params The list of parameters
	 *
	 * @return array
	 */
	public static function googleizeParams(array $params)
	{

		$googleizedParams = [];

		foreach($params as $k => $v)
		{

			if (isset(Settings::GoogleParamNames[$k]))
			{
				$googleizedParams[Settings::GoogleParamNames[$k]] = $v;
			}
			else
			{
				$googleizedParams[$k] = $v;
			}

		}

		return $googleizedParams;

	}

	/**
	 * Assembles a Universal Analytics tracker URL and fires off a request to the Google Analytics service.
	 *
	 * @param array $params
	 *
	 * @return bool Whether the tracking request was successful.
	 */
	public function track(array $params = [])
	{

		try
		{

			$trackerParams = static::getTrackerParams($params);

			$gaUrl = UrlHelper::url('https://www.google-analytics.com/collect', $trackerParams, 'https');

			$client = Craft::createGuzzleClient([
				'headers' => [
					 'user-agent' => 'Craft/' . Craft::$app->getVersion() . ' Tracker/' . $this->getVersion(),
				],
			]);

			$success = $client->get($gaUrl)->getStatusCode() === 200;

			if (Craft::$app->config->general->devMode)
			{

				if ($success)
				{
					Craft::info("Tracker request successful! ({$gaUrl})");
					return true;
				}

				Craft::error("Tracker request failed. ({$gaUrl})");
				return false;

			}

		}
		catch(\Exception $e)
		{
			Craft::error("Tracker request error. ({$gaUrl}): " . $e->getMessage());
			return false;
		}

		return true;

	}

}
