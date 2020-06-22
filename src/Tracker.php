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
	 * Uses a current user's IP address to generate a valid UUID, with some slight tweaking to conform with
	 * Google Analytics's internals. (If the IP address isn't provided or isn't valid, we use a generic string instead.)
	 *
	 * @param $ip string The current user's IP address
	 *
	 * @return string A valid UUID in the v4 format (as described at http://www.ietf.org/rfc/rfc4122.txt)
	 */
	public static function generateClientId($ip)
	{

		if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4))
		{
			$parts = array_map(function ($part) {
				return str_pad($part, 3, '0', STR_PAD_LEFT);
			}, explode('.', $ip));

			$data = implode($parts);
		}
		elseif (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6))
		{
			$parts = array_map(function ($part) {
				return str_pad($part, 4, '0', STR_PAD_LEFT);
			}, explode(':', $ip));

			$data = implode($parts);
		}
		else
		{
			$data = str_repeat(0,32);
		}

		$uuid = str_pad($data, 32, '0', STR_PAD_LEFT);
		$uuid[12] = 4;
		$accepted = ['a', 'b', 8, 9];
		if (!in_array($uuid[16], $accepted, false)) {
			$uuid[16] = 8;
		}

		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split($uuid, 4));
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

		$defaults = [
			'location' => Craft::$app->request->getUrl(),
			'clientId' => static::generateClientId(
				Craft::$app->request->getUserIP(FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)
			),
			'type' => 'pageview',
			'trackingId' => $settings->trackingId,
			'version' => '1',
		];

		return array_merge(
			static::googleizeParams($defaults),
			static::googleizeParams($settings->defaultParams),
			static::googleizeParams($params)
		);

	}

	/**
	 * Consumes an array of parameters and, for any parameter handle that is defined in $googleParamNames,
	 * swaps the friendly-style key with a Google-style parameter handle.
	 *
	 * @param array $params The list of parameters
	 *
	 * @return array
	 */
	public static function googleizeParams(array $params = [])
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
