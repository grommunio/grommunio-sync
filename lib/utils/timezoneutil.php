<?php
/*
 * SPDX-License-Identifier: AGPL-3.0-only
 * SPDX-FileCopyrightText: Copyright 2007-2016 Zarafa Deutschland GmbH
 * SPDX-FileCopyrightText: Copyright 2020-2022 grommunio GmbH
 *
 * Class to generate AS compatible timezone information
 */

class TimezoneUtil {
	/**
	 * list of MS and AS compatible timezones.
	 *
	 * origin: http://msdn.microsoft.com/en-us/library/ms912391%28v=winembedded.11%29.aspx
	 * dots of tz identifiers were removed
	 *
	 * Updated at: 01.06.2012
	 */
	private static $mstzones = [
		"000" => ["Dateline Standard Time", "(GMT-12:00) International Date Line West"],
		"001" => ["Samoa Standard Time", "(GMT-11:00) Midway Island, Samoa"],
		"002" => ["Hawaiian Standard Time", "(GMT-10:00) Hawaii"],
		"003" => ["Alaskan Standard Time", "(GMT-09:00) Alaska"],
		"004" => ["Pacific Standard Time", "(GMT-08:00) Pacific Time (US and Canada); Tijuana"],
		"010" => ["Mountain Standard Time", "(GMT-07:00) Mountain Time (US and Canada)"],
		"013" => ["Mexico Standard Time 2", "(GMT-07:00) Chihuahua, La Paz, Mazatlan"],
		"015" => ["US Mountain Standard Time", "(GMT-07:00) Arizona"],
		"020" => ["Central Standard Time", "(GMT-06:00) Central Time (US and Canada"],
		"025" => ["Canada Central Standard Time", "(GMT-06:00) Saskatchewan"],
		"030" => ["Mexico Standard Time", "(GMT-06:00) Guadalajara, Mexico City, Monterrey"],
		"033" => ["Central America Standard Time", "(GMT-06:00) Central America"],
		"035" => ["Eastern Standard Time", "(GMT-05:00) Eastern Time (US and Canada)"],
		"040" => ["US Eastern Standard Time", "(GMT-05:00) Indiana (East)"],
		"045" => ["SA Pacific Standard Time", "(GMT-05:00) Bogota, Lima, Quito"],
		"uk1" => ["Venezuela Standard Time", "(GMT-04:30) Caracas"],                     // added
		"050" => ["Atlantic Standard Time", "(GMT-04:00) Atlantic Time (Canada)"],
		"055" => ["SA Western Standard Time", "(GMT-04:00) Caracas, La Paz"],
		"056" => ["Pacific SA Standard Time", "(GMT-04:00) Santiago"],
		"060" => ["Newfoundland and Labrador Standard Time", "(GMT-03:30) Newfoundland and Labrador"],
		"065" => ["E South America Standard Time", "(GMT-03:00) Brasilia"],
		"070" => ["SA Eastern Standard Time", "(GMT-03:00) Buenos Aires, Georgetown"],
		"073" => ["Greenland Standard Time", "(GMT-03:00) Greenland"],
		"075" => ["Mid-Atlantic Standard Time", "(GMT-02:00) Mid-Atlantic"],
		"080" => ["Azores Standard Time", "(GMT-01:00) Azores"],
		"083" => ["Cape Verde Standard Time", "(GMT-01:00) Cape Verde Islands"],
		"085" => ["GMT Standard Time", "(GMT) Greenwich Mean Time: Dublin, Edinburgh, Lisbon, London"],
		"090" => ["Greenwich Standard Time", "(GMT) Casablanca, Monrovia"],
		"095" => ["Central Europe Standard Time", "(GMT+01:00) Belgrade, Bratislava, Budapest, Ljubljana, Prague"],
		"100" => ["Central European Standard Time", "(GMT+01:00) Sarajevo, Skopje, Warsaw, Zagreb"],
		"105" => ["Romance Standard Time", "(GMT+01:00) Brussels, Copenhagen, Madrid, Paris"],
		"110" => ["W Europe Standard Time", "(GMT+01:00) Amsterdam, Berlin, Bern, Rome, Stockholm, Vienna"],
		"111" => ["W. Europe Standard Time", "(GMT+01:00) Amsterdam, Berlin, Bern, Rome, Stockholm, Vienna"],
		"113" => ["W Central Africa Standard Time", "(GMT+01:00) West Central Africa"],
		"115" => ["E Europe Standard Time", "(GMT+02:00) Bucharest"],
		"120" => ["Egypt Standard Time", "(GMT+02:00) Cairo"],
		"125" => ["FLE Standard Time", "(GMT+02:00) Helsinki, Kiev, Riga, Sofia, Tallinn, Vilnius"],
		"130" => ["GTB Standard Time", "(GMT+02:00) Athens, Istanbul, Minsk"],
		"135" => ["Israel Standard Time", "(GMT+02:00) Jerusalem"],
		"140" => ["South Africa Standard Time", "(GMT+02:00) Harare, Pretoria"],
		"145" => ["Russian Standard Time", "(GMT+03:00) Moscow, St. Petersburg, Volgograd"],
		"150" => ["Arab Standard Time", "(GMT+03:00) Kuwait, Riyadh"],
		"155" => ["E Africa Standard Time", "(GMT+03:00) Nairobi"],
		"158" => ["Arabic Standard Time", "(GMT+03:00) Baghdad"],
		"160" => ["Iran Standard Time", "(GMT+03:30) Tehran"],
		"165" => ["Arabian Standard Time", "(GMT+04:00) Abu Dhabi, Muscat"],
		"170" => ["Caucasus Standard Time", "(GMT+04:00) Baku, Tbilisi, Yerevan"],
		"175" => ["Transitional Islamic State of Afghanistan Standard Time", "(GMT+04:30) Kabul"],
		"180" => ["Ekaterinburg Standard Time", "(GMT+05:00) Ekaterinburg"],
		"185" => ["West Asia Standard Time", "(GMT+05:00) Islamabad, Karachi, Tashkent"],
		"190" => ["India Standard Time", "(GMT+05:30) Chennai, Kolkata, Mumbai, New Delhi"],
		"193" => ["Nepal Standard Time", "(GMT+05:45) Kathmandu"],
		"195" => ["Central Asia Standard Time", "(GMT+06:00) Astana, Dhaka"],
		"200" => ["Sri Lanka Standard Time", "(GMT+06:00) Sri Jayawardenepura"],
		"201" => ["N Central Asia Standard Time", "(GMT+06:00) Almaty, Novosibirsk"],
		"203" => ["Myanmar Standard Time", "(GMT+06:30) Yangon Rangoon"],
		"205" => ["SE Asia Standard Time", "(GMT+07:00) Bangkok, Hanoi, Jakarta"],
		"207" => ["North Asia Standard Time", "(GMT+07:00) Krasnoyarsk"],
		"210" => ["China Standard Time", "(GMT+08:00) Beijing, Chongqing, Hong Kong SAR, Urumqi"],
		"215" => ["Singapore Standard Time", "(GMT+08:00) Kuala Lumpur, Singapore"],
		"220" => ["Taipei Standard Time", "(GMT+08:00) Taipei"],
		"225" => ["W Australia Standard Time", "(GMT+08:00) Perth"],
		"227" => ["North Asia East Standard Time", "(GMT+08:00) Irkutsk, Ulaanbaatar"],
		"230" => ["Korea Standard Time", "(GMT+09:00) Seoul"],
		"235" => ["Tokyo Standard Time", "(GMT+09:00) Osaka, Sapporo, Tokyo"],
		"240" => ["Yakutsk Standard Time", "(GMT+09:00) Yakutsk"],
		"245" => ["AUS Central Standard Time", "(GMT+09:30) Darwin"],
		"250" => ["Cen Australia Standard Time", "(GMT+09:30) Adelaide"],
		"255" => ["AUS Eastern Standard Time", "(GMT+10:00) Canberra, Melbourne, Sydney"],
		"260" => ["E Australia Standard Time", "(GMT+10:00) Brisbane"],
		"265" => ["Tasmania Standard Time", "(GMT+10:00) Hobart"],
		"270" => ["Vladivostok Standard Time", "(GMT+10:00) Vladivostok"],
		"275" => ["West Pacific Standard Time", "(GMT+10:00) Guam, Port Moresby"],
		"280" => ["Central Pacific Standard Time", "(GMT+11:00) Magadan, Solomon Islands, New Caledonia"],
		"285" => ["Fiji Islands Standard Time", "(GMT+12:00) Fiji Islands, Kamchatka, Marshall Islands"],
		"290" => ["New Zealand Standard Time", "(GMT+12:00) Auckland, Wellington"],
		"300" => ["Tonga Standard Time", "(GMT+13:00) Nuku'alofa"],
	];

	/**
	 * Python generated offset list
	 * dots in keys were removed.
	 *
	 * Array indices
	 *  0 = lBias
	 *  1 = lStandardBias
	 *  2 = lDSTBias
	 *  3 = wStartYear
	 *  4 = wStartMonth
	 *  5 = wStartDOW
	 *  6 = wStartDay
	 *  7 = wStartHour
	 *  8 = wStartMinute
	 *  9 = wStartSecond
	 * 10 = wStartMilliseconds
	 * 11 = wEndYear
	 * 12 = wEndMonth
	 * 13 = wEndDOW
	 * 14 = wEndDay
	 * 15 = wEndHour
	 * 16 = wEndMinute
	 * 17 = wEndSecond
	 * 18 = wEndMilloseconds
	 *
	 * As the $tzoneoffsets and the $mstzones need to be resolved in both directions,
	 * some offsets are commented as they are not available in the $mstzones.
	 *
	 * Created at: 01.06.2012
	 */
	private static $tzonesoffsets = [
		"Transitional Islamic State of Afghanistan Standard Time" => [-270, 0, -60, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
		"Alaskan Standard Time" => [540, 0, -60, 0, 11, 0, 1, 2, 0, 0, 0, 0, 3, 0, 2, 2, 0, 0, 0],
		"Arab Standard Time" => [-180, 0, -60, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
		"Arabian Standard Time" => [-240, 0, -60, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
		"Arabic Standard Time" => [-180, 0, -60, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
		// "Argentina Standard Time"                   => array(180, 0, -60,  0, 0, 0, 0, 0, 0, 0, 0,  0, 0, 0, 0, 0, 0, 0, 0),
		"Atlantic Standard Time" => [240, 0, -60, 0, 11, 0, 1, 2, 0, 0, 0, 0, 3, 0, 2, 2, 0, 0, 0],
		"AUS Central Standard Time" => [-570, 0, -60, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
		"AUS Eastern Standard Time" => [-600, 0, -60, 0, 4, 0, 1, 3, 0, 0, 0, 0, 10, 0, 1, 2, 0, 0, 0],
		// "Azerbaijan Standard Time"                  => array(-240, 0, -60,  0, 10, 0, 5, 5, 0, 0, 0,  0, 3, 0, 5, 4, 0, 0, 0),
		"Azores Standard Time" => [60, 0, -60, 0, 10, 0, 5, 3, 0, 0, 0, 0, 3, 0, 5, 2, 0, 0, 0],
		// "Bangladesh Standard Time"                  => array(-360, 0, -60,  0, 0, 0, 0, 0, 0, 0, 0,  0, 0, 0, 0, 0, 0, 0, 0),
		"Canada Central Standard Time" => [360, 0, -60, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
		"Cape Verde Standard Time" => [60, 0, -60, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
		"Caucasus Standard Time" => [-240, 0, -60, 0, 10, 0, 5, 3, 0, 0, 0, 0, 3, 0, 5, 2, 0, 0, 0],
		"Cen Australia Standard Time" => [-570, 0, -60, 0, 4, 0, 1, 3, 0, 0, 0, 0, 10, 0, 1, 2, 0, 0, 0],
		"Central America Standard Time" => [360, 0, -60, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
		"Central Asia Standard Time" => [-360, 0, -60, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
		// "Central Brazilian Standard Time"           => array(240, 0, -60,  0, 2, 6, 4, 23, 59, 59, 999,  0, 10, 6, 3, 23, 59, 59, 999),
		"Central Europe Standard Time" => [-60, 0, -60, 0, 10, 0, 5, 3, 0, 0, 0, 0, 3, 0, 5, 2, 0, 0, 0],
		"Central European Standard Time" => [-60, 0, -60, 0, 10, 0, 5, 3, 0, 0, 0, 0, 3, 0, 5, 2, 0, 0, 0],
		"Central Pacific Standard Time" => [-660, 0, -60, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
		"Central Standard Time" => [360, 0, -60, 0, 11, 0, 1, 2, 0, 0, 0, 0, 3, 0, 2, 2, 0, 0, 0],
		"Mexico Standard Time" => [360, 0, -60, 0, 10, 0, 5, 2, 0, 0, 0, 0, 4, 0, 1, 2, 0, 0, 0],
		"China Standard Time" => [-480, 0, -60, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
		"Dateline Standard Time" => [720, 0, -60, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
		"E Africa Standard Time" => [-180, 0, -60, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
		"E Australia Standard Time" => [-600, 0, -60, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
		"E Europe Standard Time" => [-120, 0, -60, 0, 10, 0, 5, 3, 0, 0, 0, 0, 3, 0, 5, 2, 0, 0, 0],
		"E South America Standard Time" => [180, 0, -60, 0, 2, 6, 4, 23, 59, 59, 999, 0, 10, 6, 3, 23, 59, 59, 999],
		"Eastern Standard Time" => [300, 0, -60, 0, 11, 0, 1, 2, 0, 0, 0, 0, 3, 0, 2, 2, 0, 0, 0],
		"Egypt Standard Time" => [-120, 0, -60, 0, 9, 4, 5, 23, 59, 59, 999, 0, 4, 4, 5, 23, 59, 59, 999],
		"Ekaterinburg Standard Time" => [-300, 0, -60, 0, 10, 0, 5, 3, 0, 0, 0, 0, 3, 0, 5, 2, 0, 0, 0],
		"Fiji Islands Standard Time" => [-720, 0, -60, 0, 3, 0, 5, 3, 0, 0, 0, 0, 10, 0, 4, 2, 0, 0, 0],
		"FLE Standard Time" => [-120, 0, -60, 0, 10, 0, 5, 4, 0, 0, 0, 0, 3, 0, 5, 3, 0, 0, 0],
		// "Georgian Standard Time"                    => array(-240, 0, -60,  0, 0, 0, 0, 0, 0, 0, 0,  0, 0, 0, 0, 0, 0, 0, 0),
		"GMT Standard Time" => [0, 0, -60, 0, 10, 0, 5, 2, 0, 0, 0, 0, 3, 0, 5, 1, 0, 0, 0],
		"Greenland Standard Time" => [180, 0, -60, 0, 10, 6, 5, 23, 0, 0, 0, 0, 3, 6, 4, 22, 0, 0, 0],
		"Greenwich Standard Time" => [0, 0, -60, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
		"GTB Standard Time" => [-120, 0, -60, 0, 10, 0, 5, 4, 0, 0, 0, 0, 3, 0, 5, 3, 0, 0, 0],
		"Hawaiian Standard Time" => [600, 0, -60, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
		"India Standard Time" => [-330, 0, -60, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
		"Iran Standard Time" => [-210, 0, -60, 0, 9, 1, 3, 23, 59, 59, 999, 0, 3, 6, 3, 23, 59, 59, 999],
		"Israel Standard Time" => [-120, 0, -60, 0, 9, 0, 4, 2, 0, 0, 0, 0, 3, 5, 5, 2, 0, 0, 0],
		// "Jordan Standard Time"                      => array(-120, 0, -60,  0, 10, 5, 5, 1, 0, 0, 0,  0, 3, 4, 5, 23, 59, 59, 999),
		// "Kamchatka Standard Time"                   => array(-720, 0, -60,  0, 10, 0, 5, 3, 0, 0, 0,  0, 3, 0, 5, 2, 0, 0, 0),
		"Korea Standard Time" => [-540, 0, -60, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
		// "Magadan Standard Time"                     => array(-660, 0, -60,  0, 10, 0, 5, 3, 0, 0, 0,  0, 3, 0, 5, 2, 0, 0, 0),
		// "Mauritius Standard Time"                   => array(-240, 0, -60,  0, 0, 0, 0, 0, 0, 0, 0,  0, 0, 0, 0, 0, 0, 0, 0),
		"Mid-Atlantic Standard Time" => [120, 0, -60, 0, 9, 0, 5, 2, 0, 0, 0, 0, 3, 0, 5, 2, 0, 0, 0],
		// "Middle East Standard Time"                 => array(-120, 0, -60,  0, 10, 6, 5, 23, 59, 59, 999,  0, 3, 6, 4, 23, 59, 59, 999),
		// "Montevideo Standard Time"                  => array(180, 0, -60,  0, 3, 0, 2, 2, 0, 0, 0,  0, 10, 0, 1, 2, 0, 0, 0),
		// "Morocco Standard Time"                     => array(0, 0, -60,  0, 0, 0, 0, 0, 0, 0, 0,  0, 0, 0, 0, 0, 0, 0, 0),
		"Mountain Standard Time" => [420, 0, -60, 0, 11, 0, 1, 2, 0, 0, 0, 0, 3, 0, 2, 2, 0, 0, 0],
		"Mexico Standard Time 2" => [420, 0, -60, 0, 10, 0, 5, 2, 0, 0, 0, 0, 4, 0, 1, 2, 0, 0, 0],
		"Myanmar Standard Time" => [-390, 0, -60, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
		"N Central Asia Standard Time" => [-360, 0, -60, 0, 10, 0, 5, 3, 0, 0, 0, 0, 3, 0, 5, 2, 0, 0, 0],
		// "Namibia Standard Time"                     => array(-60, 0, -60,  0, 4, 0, 1, 2, 0, 0, 0,  0, 9, 0, 1, 2, 0, 0, 0),
		"Nepal Standard Time" => [-345, 0, -60, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
		"New Zealand Standard Time" => [-720, 0, -60, 0, 4, 0, 1, 3, 0, 0, 0, 0, 9, 0, 5, 2, 0, 0, 0],
		"Newfoundland and Labrador Standard Time" => [210, 0, -60, 0, 11, 0, 1, 0, 1, 0, 0, 0, 3, 0, 2, 0, 1, 0, 0],
		"North Asia East Standard Time" => [-480, 0, -60, 0, 10, 0, 5, 3, 0, 0, 0, 0, 3, 0, 5, 2, 0, 0, 0],
		"North Asia Standard Time" => [-420, 0, -60, 0, 10, 0, 5, 3, 0, 0, 0, 0, 3, 0, 5, 2, 0, 0, 0],
		"Pacific SA Standard Time" => [240, 0, -60, 0, 3, 6, 2, 23, 59, 59, 999, 0, 10, 6, 2, 23, 59, 59, 999],
		"Pacific Standard Time" => [480, 0, -60, 0, 11, 0, 1, 2, 0, 0, 0, 0, 3, 0, 2, 2, 0, 0, 0],
		// "Pacific Standard Time (Mexico)"            => array(480, 0, -60,  0, 10, 0, 5, 2, 0, 0, 0,  0, 4, 0, 1, 2, 0, 0, 0),
		// "Pakistan Standard Time"                    => array(-300, 0, -60,  0, 0, 0, 0, 0, 0, 0, 0,  0, 0, 0, 0, 0, 0, 0, 0),
		// "Paraguay Standard Time"                    => array(240, 0, -60,  0, 4, 6, 1, 23, 59, 59, 999,  0, 10, 6, 1, 23, 59, 59, 999),
		"Romance Standard Time" => [-60, 0, -60, 0, 10, 0, 5, 3, 0, 0, 0, 0, 3, 0, 5, 2, 0, 0, 0],
		"Russian Standard Time" => [-180, 0, -60, 0, 10, 0, 5, 3, 0, 0, 0, 0, 3, 0, 5, 2, 0, 0, 0],
		"SA Eastern Standard Time" => [180, 0, -60, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
		"SA Pacific Standard Time" => [300, 0, -60, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
		"SA Western Standard Time" => [240, 0, -60, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
		"Samoa Standard Time" => [660, 0, -60, 0, 3, 6, 5, 23, 59, 59, 999, 0, 9, 6, 5, 23, 59, 59, 999],
		"SE Asia Standard Time" => [-420, 0, -60, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
		"Singapore Standard Time" => [-480, 0, -60, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
		"South Africa Standard Time" => [-120, 0, -60, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
		"Sri Lanka Standard Time" => [-330, 0, -60, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
		// "Syria Standard Time"                       => array(-120, 0, -60,  0, 10, 4, 5, 23, 59, 59, 999,  0, 4, 4, 1, 23, 59, 59, 999),
		"Taipei Standard Time" => [-480, 0, -60, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
		"Tasmania Standard Time" => [-600, 0, -60, 0, 4, 0, 1, 3, 0, 0, 0, 0, 10, 0, 1, 2, 0, 0, 0],
		"Tokyo Standard Time" => [-540, 0, -60, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
		"Tonga Standard Time" => [-780, 0, -60, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
		// "Ulaanbaatar Standard Time"                 => array(-480, 0, -60,  0, 0, 0, 0, 0, 0, 0, 0,  0, 0, 0, 0, 0, 0, 0, 0),
		"US Eastern Standard Time" => [300, 0, -60, 0, 11, 0, 1, 2, 0, 0, 0, 0, 3, 0, 2, 2, 0, 0, 0],
		"US Mountain Standard Time" => [420, 0, -60, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
		// "UTC"                                       => array(0, 0, 0,  0, 0, 0, 0, 0, 0, 0, 0,  0, 0, 0, 0, 0, 0, 0, 0),
		// "UTC+12"                                    => array(-720, 0, 0,  0, 0, 0, 0, 0, 0, 0, 0,  0, 0, 0, 0, 0, 0, 0, 0),
		// "UTC-02"                                    => array(120, 0, 0,  0, 0, 0, 0, 0, 0, 0, 0,  0, 0, 0, 0, 0, 0, 0, 0),
		// "UTC-11"                                    => array(660, 0, 0,  0, 0, 0, 0, 0, 0, 0, 0,  0, 0, 0, 0, 0, 0, 0, 0),
		"Venezuela Standard Time" => [270, 0, -60, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
		"Vladivostok Standard Time" => [-600, 0, -60, 0, 10, 0, 5, 3, 0, 0, 0, 0, 3, 0, 5, 2, 0, 0, 0],
		"W Australia Standard Time" => [-480, 0, -60, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
		"W Central Africa Standard Time" => [-60, 0, -60, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
		"W Europe Standard Time" => [-60, 0, -60, 0, 10, 0, 5, 3, 0, 0, 0, 0, 3, 0, 5, 2, 0, 0, 0],
		"West Asia Standard Time" => [-300, 0, -60, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
		"West Pacific Standard Time" => [-600, 0, -60, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
		"Yakutsk Standard Time" => [-540, 0, -60, 0, 10, 0, 5, 3, 0, 0, 0, 0, 3, 0, 5, 2, 0, 0, 0],
	];

	/**
	 * Generated list of PHP timezones in GMT timezones.
	 *
	 * Created at: 01.06.2012
	 */
	private static $phptimezones = [
		// -720 min
		"Dateline Standard Time" => [
			"Etc/GMT+12",
		],

		// -660 min
		"Samoa Standard Time" => [
			"Etc/GMT+11",
			"Pacific/Midway",
			"Pacific/Niue",
			"Pacific/Pago_Pago",
			"Pacific/Samoa",
			"US/Samoa",
		],

		// -600 min
		"Hawaiian Standard Time" => [
			"America/Adak",
			"America/Atka",
			"Etc/GMT+10",
			"HST",
			"Pacific/Honolulu",
			"Pacific/Johnston",
			"Pacific/Rarotonga",
			"Pacific/Tahiti",
			"US/Aleutian",
			"US/Hawaii",
		],

		// -570 min
		"-570" => [
			"Pacific/Marquesas",
		],

		// -540 min
		"Alaskan Standard Time" => [
			"America/Anchorage",
			"America/Juneau",
			"America/Nome",
			"America/Sitka",
			"America/Yakutat",
			"Etc/GMT+9",
			"Pacific/Gambier",
			"US/Alaska",
		],

		// -480 min
		"Pacific Standard Time" => [
			"America/Dawson",
			"America/Ensenada",
			"America/Los_Angeles",
			"America/Metlakatla",
			"America/Santa_Isabel",
			"America/Tijuana",
			"America/Vancouver",
			"America/Whitehorse",
			"Canada/Pacific",
			"Canada/Yukon",
			"Etc/GMT+8",
			"Mexico/BajaNorte",
			"Pacific/Pitcairn",
			"PST8PDT",
			"US/Pacific",
			"US/Pacific-New",
		],

		// -420 min
		"US Mountain Standard Time" => [
			"America/Boise",
			"America/Cambridge_Bay",
			"America/Chihuahua",
			"America/Creston",
			"America/Dawson_Creek",
			"America/Denver",
			"America/Edmonton",
			"America/Hermosillo",
			"America/Inuvik",
			"America/Mazatlan",
			"America/Ojinaga",
			"America/Phoenix",
			"America/Shiprock",
			"America/Yellowknife",
			"Canada/Mountain",
			"Etc/GMT+7",
			"Mexico/BajaSur",
			"MST",
			"MST7MDT",
			"Navajo",
			"US/Arizona",
			"US/Mountain",
		],

		// -360 min
		"Central Standard Time" => [
			"America/Chicago",
			"America/Indiana/Knox",
			"America/Indiana/Tell_City",
			"America/Knox_IN",
			"America/North_Dakota/Beulah",
			"America/North_Dakota/Center",
			"America/North_Dakota/New_Salem",
			"America/Rainy_River",
			"America/Rankin_Inlet",
			"America/Regina",
			"America/Resolute",
			"America/Swift_Current",
			"America/Tegucigalpa",
			"America/Winnipeg",
			"US/Central",
			"US/Indiana-Starke",
			"CST6CDT",
			"Etc/GMT+6",
		],
		"Canada Central Standard Time" => [
			"Canada/Central",
			"Canada/East-Saskatchewan",
			"Canada/Saskatchewan",
		],
		"Mexico Standard Time" => [
			"America/Mexico_City",
			"America/Monterrey",
			"Mexico/General",
		],
		"Central America Standard Time" => [
			"America/Bahia_Banderas",
			"America/Belize",
			"America/Cancun",
			"America/Costa_Rica",
			"America/El_Salvador",
			"America/Guatemala",
			"America/Managua",
			"America/Matamoros",
			"America/Menominee",
			"America/Merida",
			"Chile/EasterIsland",
			"Pacific/Easter",
			"Pacific/Galapagos",
		],

		// -300 min
		"US Eastern Standard Time" => [
			"America/Detroit",
			"America/Fort_Wayne",
			"America/Grand_Turk",
			"America/Indiana/Indianapolis",
			"America/Indiana/Marengo",
			"America/Indiana/Petersburg",
			"America/Indiana/Vevay",
			"America/Indiana/Vincennes",
			"America/Indiana/Winamac",
			"America/Indianapolis",
			"America/Jamaica",
			"America/Kentucky/Louisville",
			"America/Kentucky/Monticello",
			"America/Louisville",
			"America/Montreal",
			"America/New_York",
			"America/Thunder_Bay",
			"America/Toronto",
			"Canada/Eastern",
			"Cuba",
			"EST",
			"EST5EDT",
			"Etc/GMT+5",
			"Jamaica",
			"US/East-Indiana",
			"US/Eastern",
			"US/Michigan",
		],
		"SA Pacific Standard Time" => [
			"America/Atikokan",
			"America/Bogota",
			"America/Cayman",
			"America/Coral_Harbour",
			"America/Guayaquil",
			"America/Havana",
			"America/Iqaluit",
			"America/Lima",
			"America/Nassau",
			"America/Nipigon",
			"America/Panama",
			"America/Pangnirtung",
			"America/Port-au-Prince",
		],

		// -270 min
		"Venezuela Standard Time" => [
			"America/Caracas",
		],
		// -240 min
		"Atlantic Standard Time" => [
			"America/Barbados",
			"America/Blanc-Sablon",
			"America/Glace_Bay",
			"America/Goose_Bay",
			"America/Halifax",
			"America/Lower_Princes",
			"America/St_Barthelemy",
			"America/St_Kitts",
			"America/St_Lucia",
			"America/St_Thomas",
			"America/St_Vincent",
			"America/Thule",
			"America/Tortola",
			"America/Virgin",
			"Atlantic/Bermuda",
			"Canada/Atlantic",
			"Etc/GMT+4",
		],
		"SA Western Standard Time" => [
			"America/Anguilla",
			"America/Antigua",
			"America/Aruba",
			"America/Asuncion",
			"America/Boa_Vista",
			"America/Campo_Grande",
			"America/Cuiaba",
			"America/Curacao",
			"America/Dominica",
			"America/Eirunepe",
			"America/Grenada",
			"America/Guadeloupe",
			"America/Guyana",
			"America/Kralendijk",
			"America/La_Paz",
			"America/Manaus",
			"America/Marigot",
			"America/Martinique",
			"America/Moncton",
			"America/Montserrat",
			"America/Port_of_Spain",
			"America/Porto_Acre",
			"America/Porto_Velho",
			"America/Puerto_Rico",
			"America/Rio_Branco",
			"Brazil/Acre",
			"Brazil/West",
		],
		"Pacific SA Standard Time" => [
			"America/Santiago",
			"America/Santo_Domingo",
			"Antarctica/Palmer",
			"Chile/Continental",
		],

		// -210 min
		"Newfoundland and Labrador Standard Time" => [
			"America/St_Johns",
			"Canada/Newfoundland",
		],

		// -180 min
		"E South America Standard Time" => [
			"America/Araguaina",
			"America/Bahia",
			"America/Belem",
			"America/Fortaleza",
			"America/Maceio",
			"America/Recife",
			"America/Sao_Paulo",
			"Brazil/East",
			"Etc/GMT+3",
		],
		"SA Eastern Standard Time" => [
			"America/Argentina/Buenos_Aires",
			"America/Argentina/Catamarca",
			"America/Argentina/ComodRivadavia",
			"America/Argentina/Cordoba",
			"America/Argentina/Jujuy",
			"America/Argentina/La_Rioja",
			"America/Argentina/Mendoza",
			"America/Argentina/Rio_Gallegos",
			"America/Argentina/Salta",
			"America/Argentina/San_Juan",
			"America/Argentina/San_Luis",
			"America/Argentina/Tucuman",
			"America/Argentina/Ushuaia",
			"America/Buenos_Aires",
			"America/Catamarca",
			"America/Cayenne",
			"America/Cordoba",
			"America/Godthab",
			"America/Jujuy",
			"America/Mendoza",
			"America/Miquelon",
			"America/Montevideo",
			"America/Paramaribo",
			"America/Rosario",
			"America/Santarem",
		],
		"Greenland Standard Time" => [
			"Antarctica/Rothera",
			"Atlantic/Stanley",
		],

		// -120 min
		"Mid-Atlantic Standard Time" => [
			"America/Noronha",
			"Atlantic/South_Georgia",
			"Brazil/DeNoronha",
			"Etc/GMT+2",
		],

		// -60 min
		"Azores Standard Time" => [
			"Atlantic/Azores",
			"Etc/GMT+1",
		],
		"Cape Verde Standard Time" => [
			"America/Scoresbysund",
			"Atlantic/Cape_Verde",
		],

		// 0 min
		"GMT Standard Time" => [
			"Eire",
			"Etc/GMT",
			"Etc/GMT+0",
			"Etc/GMT-0",
			"Etc/GMT0",
			"Etc/Greenwich",
			"Etc/UCT",
			"Etc/Universal",
			"Etc/UTC",
			"Etc/Zulu",
			"Europe/Belfast",
			"Europe/Dublin",
			"Europe/Guernsey",
			"Europe/Isle_of_Man",
			"Europe/Jersey",
			"Europe/Lisbon",
			"Europe/London",
			"Factory",
			"GB",
			"GB-Eire",
			"GMT",
			"GMT+0",
			"GMT-0",
			"GMT0",
			"Greenwich",
			"Iceland",
			"Portugal",
			"UCT",
			"Universal",
			"UTC",
		],
		"Greenwich Standard Time" => [
			"Africa/Abidjan",
			"Africa/Accra",
			"Africa/Bamako",
			"Africa/Banjul",
			"Africa/Bissau",
			"Africa/Casablanca",
			"Africa/Conakry",
			"Africa/Dakar",
			"Africa/El_Aaiun",
			"Africa/Freetown",
			"Africa/Lome",
			"Africa/Monrovia",
			"Africa/Nouakchott",
			"Africa/Ouagadougou",
			"Africa/Sao_Tome",
			"Africa/Timbuktu",
			"America/Danmarkshavn",
			"Atlantic/Canary",
			"Atlantic/Faeroe",
			"Atlantic/Faroe",
			"Atlantic/Madeira",
			"Atlantic/Reykjavik",
			"Atlantic/St_Helena",
			"Zulu",
		],

		// +60 min
		"Central Europe Standard Time" => [
			"Europe/Belgrade",
			"Europe/Bratislava",
			"Europe/Budapest",
			"Europe/Ljubljana",
			"Europe/Prague",
			"Europe/Vaduz",
		],
		"Central European Standard Time" => [
			"Europe/Sarajevo",
			"Europe/Skopje",
			"Europe/Warsaw",
			"Europe/Zagreb",
			"MET",
			"Poland",
		],
		"Romance Standard Time" => [
			"Europe/Andorra",
			"Europe/Brussels",
			"Europe/Copenhagen",
			"Europe/Gibraltar",
			"Europe/Madrid",
			"Europe/Malta",
			"Europe/Monaco",
			"Europe/Paris",
			"Europe/Podgorica",
			"Europe/San_Marino",
			"Europe/Tirane",
		],
		"W Europe Standard Time" => [
			"Europe/Amsterdam",
			"Europe/Berlin",
			"Europe/Luxembourg",
			"Europe/Vatican",
			"Europe/Rome",
			"Europe/Stockholm",
			"Arctic/Longyearbyen",
			"Europe/Vienna",
			"Europe/Zurich",
			"Europe/Oslo",
			"WET",
			"CET",
			"Etc/GMT-1",
		],
		"W Central Africa Standard Time" => [
			"Africa/Algiers",
			"Africa/Bangui",
			"Africa/Brazzaville",
			"Africa/Ceuta",
			"Africa/Douala",
			"Africa/Kinshasa",
			"Africa/Lagos",
			"Africa/Libreville",
			"Africa/Luanda",
			"Africa/Malabo",
			"Africa/Ndjamena",
			"Africa/Niamey",
			"Africa/Porto-Novo",
			"Africa/Tunis",
			"Africa/Windhoek",
			"Atlantic/Jan_Mayen",
		],

		// +120 min
		"E Europe Standard Time" => [
			"Europe/Bucharest",
			"EET",
			"Etc/GMT-2",
			"Europe/Chisinau",
			"Europe/Mariehamn",
			"Europe/Nicosia",
			"Europe/Simferopol",
			"Europe/Tiraspol",
			"Europe/Uzhgorod",
			"Europe/Zaporozhye",
		],
		"Egypt Standard Time" => [
			"Africa/Cairo",
			"Africa/Tripoli",
			"Egypt",
			"Libya",
		],
		"FLE Standard Time" => [
			"Europe/Helsinki",
			"Europe/Kiev",
			"Europe/Riga",
			"Europe/Sofia",
			"Europe/Tallinn",
			"Europe/Vilnius",
		],
		"GTB Standard Time" => [
			"Asia/Istanbul",
			"Europe/Athens",
			"Europe/Istanbul",
			"Turkey",
		],
		"Israel Standard Time" => [
			"Asia/Amman",
			"Asia/Beirut",
			"Asia/Damascus",
			"Asia/Gaza",
			"Asia/Hebron",
			"Asia/Nicosia",
			"Asia/Tel_Aviv",
			"Asia/Jerusalem",
			"Israel",
		],
		"South Africa Standard Time" => [
			"Africa/Blantyre",
			"Africa/Bujumbura",
			"Africa/Gaborone",
			"Africa/Harare",
			"Africa/Johannesburg",
			"Africa/Kigali",
			"Africa/Lubumbashi",
			"Africa/Lusaka",
			"Africa/Maputo",
			"Africa/Maseru",
			"Africa/Mbabane",
		],

		// +180 min
		"Russian Standard Time" => [
			"Antarctica/Syowa",
			"Europe/Kaliningrad",
			"Europe/Minsk",
			"Etc/GMT-3",
		],
		"Arab Standard Time" => [
			"Asia/Qatar",
			"Asia/Kuwait",
			"Asia/Riyadh",
		],
		"E Africa Standard Time" => [
			"Africa/Addis_Ababa",
			"Africa/Asmara",
			"Africa/Asmera",
			"Africa/Dar_es_Salaam",
			"Africa/Djibouti",
			"Africa/Juba",
			"Africa/Kampala",
			"Africa/Khartoum",
			"Africa/Mogadishu",
			"Africa/Nairobi",
		],
		"Arabic Standard Time" => [
			"Asia/Aden",
			"Asia/Baghdad",
			"Asia/Bahrain",
			"Indian/Antananarivo",
			"Indian/Comoro",
			"Indian/Mayotte",
		],

		// +210 min
		"Iran Standard Time" => [
			"Asia/Tehran",
			"Iran",
		],

		// +240 min
		"Arabian Standard Time" => [
			"Asia/Dubai",
			"Asia/Muscat",
			"Indian/Mahe",
			"Indian/Mauritius",
			"Indian/Reunion",
		],
		"Caucasus Standard Time" => [
			"Asia/Baku",
			"Asia/Tbilisi",
			"Asia/Yerevan",
			"Etc/GMT-4",
			"Europe/Moscow",
			"Europe/Samara",
			"Europe/Volgograd",
			"W-SU",
		],

		// +270 min
		"Transitional Islamic State of Afghanistan Standard Time" => [
			"Asia/Kabul",
		],

		// +300 min
		"Ekaterinburg Standard Time" => [
			"Antarctica/Mawson",
		],
		"West Asia Standard Time" => [
			"Asia/Aqtau",
			"Asia/Aqtobe",
			"Asia/Ashgabat",
			"Asia/Ashkhabad",
			"Asia/Dushanbe",
			"Asia/Karachi",
			"Asia/Oral",
			"Asia/Samarkand",
			"Asia/Tashkent",
			"Etc/GMT-5",
			"Indian/Kerguelen",
			"Indian/Maldives",
		],

		// +330 min
		"India Standard Time" => [
			"Asia/Calcutta",
			"Asia/Colombo",
			"Asia/Kolkata",
		],

		// +345 min
		"Nepal Standard Time" => [
			"Asia/Kathmandu",
			"Asia/Katmandu",
		],

		// +360 min
		"Central Asia Standard Time" => [
			"Asia/Dacca",
			"Asia/Dhaka",
		],
		"Sri Lanka Standard Time" => [
			"Indian/Chagos",
		],
		"N Central Asia Standard Time" => [
			"Antarctica/Vostok",
			"Asia/Almaty",
			"Asia/Bishkek",
			"Asia/Qyzylorda",
			"Asia/Thimbu",
			"Asia/Thimphu",
			"Asia/Yekaterinburg",
			"Etc/GMT-6",
		],

		// +390 min
		"Myanmar Standard Time" => [
			"Asia/Rangoon",
			"Indian/Cocos",
		],

		// +420 min
		"SE Asia Standard Time" => [
			"Asia/Bangkok",
			"Asia/Ho_Chi_Minh",
			"Asia/Hovd",
			"Asia/Jakarta",
			"Asia/Phnom_Penh",
			"Asia/Saigon",
			"Indian/Christmas",
		],
		"North Asia Standard Time" => [
			"Antarctica/Davis",
			"Asia/Novokuznetsk",
			"Asia/Novosibirsk",
			"Asia/Omsk",
			"Asia/Pontianak",
			"Asia/Vientiane",
			"Etc/GMT-7",
		],

		// +480 min
		"China Standard Time" => [
			"Asia/Brunei",
			"Asia/Choibalsan",
			"Asia/Chongqing",
			"Asia/Chungking",
			"Asia/Harbin",
			"Asia/Hong_Kong",
			"Asia/Shanghai",
			"Asia/Ujung_Pandang",
			"Asia/Urumqi",
			"Hongkong",
			"PRC",
			"ROC",
		],
		"Singapore Standard Time" => [
			"Singapore",
			"Asia/Singapore",
			"Asia/Kuala_Lumpur",
		],
		"Taipei Standard Time" => [
			"Asia/Taipei",
		],
		"W Australia Standard Time" => [
			"Australia/Perth",
			"Australia/West",
		],
		"North Asia East Standard Time" => [
			"Antarctica/Casey",
			"Asia/Kashgar",
			"Asia/Krasnoyarsk",
			"Asia/Kuching",
			"Asia/Macao",
			"Asia/Macau",
			"Asia/Makassar",
			"Asia/Manila",
			"Etc/GMT-8",
			"Asia/Ulaanbaatar",
			"Asia/Ulan_Bator",
		],

		// +525 min
		"525" => [
			"Australia/Eucla",
		],

		// +540 min
		"Korea Standard Time" => [
			"Asia/Seoul",
			"Asia/Pyongyang",
			"ROK",
		],
		"Tokyo Standard Time" => [
			"Asia/Tokyo",
			"Japan",
			"Etc/GMT-9",
		],
		"Yakutsk Standard Time" => [
			"Asia/Dili",
			"Asia/Irkutsk",
			"Asia/Jayapura",
			"Pacific/Palau",
		],

		// +570 min
		"AUS Central Standard Time" => [
			"Australia/Darwin",
			"Australia/North",
		],
		// DST
		"Cen Australia Standard Time" => [
			"Australia/Adelaide",
			"Australia/Broken_Hill",
			"Australia/South",
			"Australia/Yancowinna",
		],

		// +600 min
		"AUS Eastern Standard Time" => [
			"Australia/Canberra",
			"Australia/Melbourne",
			"Australia/Sydney",
			"Australia/Currie",
			"Australia/ACT",
			"Australia/NSW",
			"Australia/Victoria",
		],
		"E Australia Standard Time" => [
			"Etc/GMT-10",
			"Australia/Brisbane",
			"Australia/Queensland",
			"Australia/Lindeman",
		],
		"Tasmania Standard Time" => [
			"Australia/Hobart",
			"Australia/Tasmania",
		],
		"Vladivostok Standard Time" => [
			"Antarctica/DumontDUrville",
		],
		"West Pacific Standard Time" => [
			"Asia/Yakutsk",
			"Pacific/Chuuk",
			"Pacific/Guam",
			"Pacific/Port_Moresby",
			"Pacific/Saipan",
			"Pacific/Truk",
			"Pacific/Yap",
		],

		// +630 min
		"630" => [
			"Australia/LHI",
			"Australia/Lord_Howe",
		],

		// +660 min
		"Central Pacific Standard Time" => [
			"Antarctica/Macquarie",
			"Asia/Sakhalin",
			"Asia/Vladivostok",
			"Etc/GMT-11",
			"Pacific/Efate",
			"Pacific/Guadalcanal",
			"Pacific/Kosrae",
			"Pacific/Noumea",
			"Pacific/Pohnpei",
			"Pacific/Ponape",
		],

		// 690 min
		"690" => [
			"Pacific/Norfolk",
		],

		// +720 min
		"Fiji Islands Standard Time" => [
			"Asia/Anadyr",
			"Asia/Kamchatka",
			"Asia/Magadan",
			"Kwajalein",
		],
		"New Zealand Standard Time" => [
			"Antarctica/McMurdo",
			"Antarctica/South_Pole",
			"Etc/GMT-12",
			"NZ",
			"Pacific/Auckland",
			"Pacific/Fiji",
			"Pacific/Funafuti",
			"Pacific/Kwajalein",
			"Pacific/Majuro",
			"Pacific/Nauru",
			"Pacific/Tarawa",
			"Pacific/Wake",
			"Pacific/Wallis",
		],

		// +765 min
		"765" => [
			"NZ-CHAT",
			"Pacific/Chatham",
		],

		// +780 min
		"Tonga Standard Time" => [
			"Etc/GMT-13",
			"Pacific/Apia",
			"Pacific/Enderbury",
			"Pacific/Tongatapu",
		],

		// +840 min
		"840" => [
			"Etc/GMT-14",
			"Pacific/Fakaofo",
			"Pacific/Kiritimati",
		],
	];

	/**
	 * Returns the binary timezone definition.
	 *
	 * @param string $phptimezone (opt) a php timezone string.
	 *                            If omitted the env. default timezone is used.
	 *
	 * @return array|bool
	 */
	public static function GetBinaryTZ($phptimezone = false) {
		if ($phptimezone === false) {
			$phptimezone = date_default_timezone_get();
		}
		SLog::Write(LOGLEVEL_DEBUG, sprintf("TimezoneUtil::GetBinaryTZ() for %s", $phptimezone));

		try {
			return mapi_ianatz_to_tzdef($phptimezone);
		}
		catch (Exception $e) {
			SLog::Write(LOGLEVEL_WARN, sprintf("TimezoneUtil::GetBinaryTZ() mapi_ianatz_to_tzdef() for '%s' failed!", $phptimezone));
		}

		return false;
	}

	/**
	 * Returns a full timezone array.
	 *
	 * @param string $phptimezone (opt) a php timezone string.
	 *                            If omitted the env. default timezone is used.
	 *
	 * @return array
	 */
	public static function GetFullTZ($phptimezone = false) {
		if ($phptimezone === false) {
			$phptimezone = date_default_timezone_get();
		}

		SLog::Write(LOGLEVEL_DEBUG, "TimezoneUtil::GetFullTZ() for " . $phptimezone);

		$servertzname = self::guessTZNameFromPHPName($phptimezone);

		return self::GetFullTZFromTZName($servertzname);
	}

	/**
	 * Returns a full timezone array.
	 *
	 * @param string $tzname a TZID value
	 *
	 * @return array
	 */
	public static function GetFullTZFromTZName($tzname) {
		if (!array_key_exists($tzname, self::$tzonesoffsets)) {
			SLog::Write(LOGLEVEL_DEBUG, sprintf("TimezoneUtil::GetFullTZFromTZName('%s'): Is a PHP TimeZone, converting", $tzname));
			$tzname = self::guessTZNameFromPHPName($tzname);
		}

		$offset = self::$tzonesoffsets[$tzname];

		return [
			"bias" => $offset[0],
			"tzname" => self::encodeTZName(self::getMSTZnameFromTZName($tzname)),
			"dstendyear" => $offset[3],
			"dstendmonth" => $offset[4],
			"dstendday" => $offset[5],
			"dstendweek" => $offset[6],
			"dstendhour" => $offset[7],
			"dstendminute" => $offset[8],
			"dstendsecond" => $offset[9],
			"dstendmillis" => $offset[10],
			"stdbias" => $offset[1],
			"tznamedst" => self::encodeTZName(self::getMSTZnameFromTZName($tzname)),
			"dststartyear" => $offset[11],
			"dststartmonth" => $offset[12],
			"dststartday" => $offset[13],
			"dststartweek" => $offset[14],
			"dststarthour" => $offset[15],
			"dststartminute" => $offset[16],
			"dststartsecond" => $offset[17],
			"dststartmillis" => $offset[18],
			"dstbias" => $offset[2],
		];
	}

	/**
	 * Sets the timezone name by matching data from the offset (bias etc).
	 *
	 * @param mixed $tz
	 *
	 * @return array
	 */
	public static function FillTZNames($tz) {
		SLog::Write(LOGLEVEL_DEBUG, "TimezoneUtil::FillTZNames() filling up bias " . $tz["bias"]);
		if (!isset($tz["bias"])) {
			SLog::Write(LOGLEVEL_WARN, "TimezoneUtil::FillTZNames() submitted TZ array does not have a bias");
		}
		else {
			$tzname = self::guessTZNameFromOffset($tz);
			$tz['tzname'] = $tz['tznamedst'] = self::encodeTZName(self::getMSTZnameFromTZName($tzname));
		}

		return $tz;
	}

	/**
	 * Tries to find a timezone using the Bias and other offset parameters.
	 *
	 * @param array $offset a grommunio-sync timezone array
	 *
	 * @return string
	 */
	private static function guessTZNameFromOffset($offset) {
		// try to find a quite exact match
		foreach (self::$tzonesoffsets as $tzname => $tzoffset) {
			if ($offset["bias"] == $tzoffset[0] &&
				isset($offset["dstendmonth"]) && $offset["dstendmonth"] == $tzoffset[4] &&
				isset($offset["dstendday"]) && $offset["dstendday"] == $tzoffset[6] &&
				isset($offset["dststartmonth"]) && $offset["dststartmonth"] == $tzoffset[12] &&
				isset($offset["dststartday"]) && $offset["dststartday"] == $tzoffset[14]) {
				return $tzname;
			}
		}

		// try to find a bias match
		foreach (self::$tzonesoffsets as $tzname => $tzoffset) {
			if ($offset["bias"] == $tzoffset[0]) {
				return $tzname;
			}
		}

		// nothing found? return gmt
		SLog::Write(LOGLEVEL_WARN, "TimezoneUtil::guessTZNameFromOffset() no timezone found for the data submitted. Returning 'GMT Standard Time'.");

		return "GMT Standard Time";
	}

	/**
	 * Tries to find a AS timezone for a php timezone.
	 *
	 * @param string $phpname a php timezone name
	 *
	 * @return string
	 */
	private static function guessTZNameFromPHPName($phpname) {
		foreach (self::$phptimezones as $tzn => $phptzs) {
			if (in_array($phpname, $phptzs)) {
				if (!is_int($tzn)) {
					return $tzn;
				}

				break;
			}
		}
		SLog::Write(LOGLEVEL_ERROR, sprintf("TimezoneUtil::guessTZNameFromPHPName() no compatible timezone found for '%s'. Returning 'GMT Standard Time'. Please contact the grommunio dev team.", $phpname));

		return self::$mstzones["085"][0];
	}

	/**
	 * Returns an AS compatible tz name.
	 *
	 * @param string $name internal timezone name
	 *
	 * @return string
	 */
	private static function getMSTZnameFromTZName($name) {
		// if $name is empty, get the timezone from system
		if (trim($name) == '') {
			$name = date_default_timezone_get();
			SLog::Write(LOGLEVEL_INFO, sprintf("TimezoneUtil::getMSTZnameFromTZName(): empty timezone name sent. Got timezone from the system: '%s'", $name));
		}

		foreach (self::$mstzones as $mskey => $msdefs) {
			if ($name == $msdefs[0]) {
				return $msdefs[1];
			}
		}

		// Not found? Then retrieve the correct TZName first and try again.
		// That's ugly and needs a proper fix. But for now this method can convert
		// - Europe/Berlin
		// - W Europe Standard Time
		// to "(GMT+01:00) Amsterdam, Berlin, Bern, Rome, Stockholm, Vienna"
		// which is more correct than the hardcoded default of (GMT+00:00...)
		$tzName = '';
		foreach (self::$phptimezones as $tzn => $phptzs) {
			if (in_array($name, $phptzs)) {
				$tzName = $tzn;

				break;
			}
		}
		if ($tzName != '') {
			foreach (self::$mstzones as $mskey => $msdefs) {
				if ($tzName == $msdefs[0]) {
					return $msdefs[1];
				}
			}
		}
		if (strpos($name, '.') !== false) {
			return self::getMSTZnameFromTZName(str_replace('.', '', $name));
		}
		SLog::Write(LOGLEVEL_WARN, sprintf("TimezoneUtil::getMSTZnameFromTZName() no MS name found for '%s'. Returning '(GMT) Greenwich Mean Time: Dublin, Edinburgh, Lisbon, London'", $name));

		return self::$mstzones["085"][1];
	}

	/**
	 * Encodes the tz name to UTF-16 compatible with a syncblob.
	 *
	 * @param string $name timezone name
	 *
	 * @return string
	 */
	private static function encodeTZName($name) {
		return substr(iconv('UTF-8', 'UTF-16', $name), 2, -1);
	}

	/**
	 * Test to check if $mstzones and $tzonesoffsets can be resolved
	 * in both directions.
	 */
	public static function TZtest() {
		foreach (self::$mstzones as $mskey => $msdefs) {
			if (!array_key_exists($msdefs[0], self::$tzonesoffsets)) {
				echo "key   '" . $msdefs[0] . "'   not found in tzonesoffsets\n";
			}
		}

		foreach (self::$tzonesoffsets as $tzname => $offset) {
			$found = false;
			foreach (self::$mstzones as $mskey => $msdefs) {
				if ($tzname == $msdefs[0]) {
					$found = true;

					break;
				}
			}
			if (!$found) {
				echo "key    '{$tzname}' NOT FOUND\n";
			}
		}
	}

	/**
	 * Pack timezone info for Sync.
	 *
	 * @param array $tz
	 *
	 * @return string
	 */
	public static function GetSyncBlobFromTZ($tz) {
		// set the correct TZ name (done using the Bias)
		if (!isset($tz["tzname"]) || !$tz["tzname"] || !isset($tz["tznamedst"]) || !$tz["tznamedst"]) {
			$tz = TimezoneUtil::FillTZNames($tz);
		}

		return pack(
			"la64vvvvvvvvla64vvvvvvvvl",
			$tz["bias"],
			$tz["tzname"],
			0,
			$tz["dstendmonth"],
			$tz["dstendday"],
			$tz["dstendweek"],
			$tz["dstendhour"],
			$tz["dstendminute"],
			$tz["dstendsecond"],
			$tz["dstendmillis"],
			$tz["stdbias"],
			$tz["tznamedst"],
			0,
			$tz["dststartmonth"],
			$tz["dststartday"],
			$tz["dststartweek"],
			$tz["dststarthour"],
			$tz["dststartminute"],
			$tz["dststartsecond"],
			$tz["dststartmillis"],
			$tz["dstbias"]
		);
	}

	/**
	 * Generate date object from string and timezone.
	 *
	 * @param string $value
	 * @param string $timezone
	 *
	 * @return int epoch
	 */
	public static function MakeUTCDate($value, $timezone = null) {
		$tz = null;
		if ($timezone) {
			$tz = timezone_open($timezone);
		}
		if (!$tz) {
			// If there is no timezone set, we use the default timezone
			$tz = timezone_open(date_default_timezone_get());
		}
		// 20110930T090000Z
		$date = date_create_from_format('Ymd\THis\Z', $value, timezone_open("UTC"));
		if (!$date) {
			// 20110930T090000
			$date = date_create_from_format('Ymd\THis', $value, $tz);
		}
		if (!$date) {
			// 20110930 (Append T000000Z to the date, so it starts at midnight)
			$date = date_create_from_format('Ymd\THis\Z', $value . "T000000Z", $tz);
		}

		return date_timestamp_get($date);
	}

	/**
	 * Generate a tzid from various formats.
	 *
	 * @param string $timezone
	 *
	 * @return timezone id
	 */
	public static function ParseTimezone($timezone) {
		// (GMT+01.00) Amsterdam / Berlin / Bern / Rome / Stockholm / Vienna
		if (preg_match('/GMT(\\+|\\-)0(\d)/', $timezone, $matches)) {
			return "Etc/GMT" . $matches[1] . $matches[2];
		}
		// (GMT+10.00) XXX / XXX / XXX / XXX
		if (preg_match('/GMT(\\+|\\-)1(\d)/', $timezone, $matches)) {
			return "Etc/GMT" . $matches[1] . "1" . $matches[2];
		}
		// /inverse.ca/20101018_1/Europe/Amsterdam or /inverse.ca/20101018_1/America/Argentina/Buenos_Aires
		if (preg_match('/\/[.[:word:]]+\/\w+\/(\w+)\/([\w\/]+)/', $timezone, $matches)) {
			return $matches[1] . "/" . $matches[2];
		}

		return self::getMSTZnameFromTZName(trim($timezone, '"'));
	}

	/**
	 * Returns a timezone supported by PHP for DateTimeZone constructor.
	 *
	 * @see http://php.net/manual/en/timezones.php
	 *
	 * @param string $timezone
	 *
	 * @return string
	 */
	public static function GetPhpSupportedTimezone($timezone) {
		if (in_array($timezone, DateTimeZone::listIdentifiers())) {
			SLog::Write(LOGLEVEL_DEBUG, sprintf("TimezoneUtil::GetPhpSupportedTimezone(): '%s' is a PHP supported timezone", $timezone));

			return $timezone;
		}
		$dtz = date_default_timezone_get();
		SLog::Write(LOGLEVEL_DEBUG, sprintf("TimezoneUtil::GetPhpSupportedTimezone(): '%s' is not a PHP supported timezone. Returning default timezone: '%s'", $timezone, $dtz));

		return $dtz;
	}

	/**
	 * Returns official timezone name from windows timezone name.
	 * E.g. "W Europe Standard Time" for "(GMT+01:00) Amsterdam, Berlin, Bern, Rome, Stockholm, Vienna".
	 *
	 * @param string $winTz Timezone name in windows
	 *
	 * @return string timezone name
	 */
	public static function GetTZNameFromWinTZ($winTz = false) {
		// Return "GMT Standard Time" per default
		if ($winTz === false) {
			return self::$mstzones['085'][0];
		}

		foreach (self::$mstzones as $mskey => $msdefs) {
			if ($winTz == $msdefs[1]) {
				return $msdefs[0];
			}
		}

		return self::$mstzones['085'][0];
	}

	/**
	 * Returns an GMT timezone array.
	 *
	 * @return array
	 */
	public static function GetGMTTz() {
		return [
			"bias" => 0,
			"tzname" => "",
			"dstendyear" => 0,
			"dstendmonth" => 10,
			"dstendday" => 0,
			"dstendweek" => 5,
			"dstendhour" => 2,
			"dstendminute" => 0,
			"dstendsecond" => 0,
			"dstendmillis" => 0,
			"stdbias" => 0,
			"tznamedst" => "",
			"dststartyear" => 0,
			"dststartmonth" => 3,
			"dststartday" => 0,
			"dststartweek" => 5,
			"dststarthour" => 1,
			"dststartminute" => 0,
			"dststartsecond" => 0,
			"dststartmillis" => 0,
			"dstbias" => -60,
		];
	}

	/**
	 * Creates an array that has split up all the components of a timezone
	 * definition binary.
	 *
	 * Timezone definition structure:
	 *
	 * Major ver : UINT, 1 byte,  2 hex characters
	 * Minor ver : UINT, 1 byte,  2 hex characters
	 * cbHeader  : UINT, 2 bytes, 4 hex characters
	 * Reserved  : UINT, 2 bytes, 4 hex characters
	 * cchKeyName: UINT, 2 bytes, 4 hex characters
	 * KeyName   : CHAR, variable length (defined by cckeyname value)
	 * cRules    : UINT, 2 bytes, 4 hex characters
	 * rules     : STRUCT, variable length (defined by cRules value):
	 *   Major ver     : UINT, 1 byte,  2 hex characters
	 *   Minor ver     : UINT, 1 byte,  2 hex characters
	 *   Reserved      : UINT, 2 bytes, 4 hex characters
	 *   TZRule flags  : UINT, 2 bytes, 4 hex characters
	 *   wYear         : UINT, 2 bytes, 4 hex characters
	 *   X             : TCHAR[14]
	 *   lBias         : LONG, 4 bytes, 8 hex characters
	 *   lStandardBias : LONG, 4 bytes, 8 hex characters
	 *   lDaylightBias : LONG, 4 bytes, 8 hex characters
	 *   stStandardDate: STRUCT
	 *   stDaylightDate: STRUCT
	 *
	 * stStandardDate/stDaylightDate:
	 *   wYear        : UINT, 2 bytes, 4 hex characters
	 *   wMonth       : UINT, 2 bytes, 4 hex characters
	 *   wDayOfWeek   : UINT, 2 bytes, 4 hex characters
	 *   wDay         : UINT, 2 bytes, 4 hex characters
	 *   wHour        : UINT, 2 bytes, 4 hex characters
	 *   wMinute      : UINT, 2 bytes, 4 hex characters
	 *   wSecond      : UINT, 2 bytes, 4 hex characters
	 *   wMilliseconds: UINT, 2 bytes, 4 hex characters
	 *
	 * @param string $tzdef Timezone definition binary
	 *
	 * @return array timezone definition array
	 */
	public static function CreateTimezoneDefinitionObject($tzdef) {
		if (!$tzdef) {
			return [];
		}

		$offset = 0;

		$res = unpack("Cmajorver/Cminorver/vcbheader/vreserved/vcchkeyname", substr($tzdef, $offset, 8));
		$offset += 8;

		$cchKeyName = $res['cchkeyname'] * 2;
		$data = unpack("a{$cchKeyName}keyname/vcrules", substr($tzdef, $offset, $cchKeyName + 2));
		$res['keyname'] = $data['keyname'];
		$res['crules'] = $data['crules'];
		$offset += $cchKeyName + 2;

		for ($i = 0; $i < $res['crules']; ++$i) {
			$rule = [];
			$rule = unpack(
				"Cmajorver/Cminorver/vreserved/vtzruleflags/vwyear/a14x/lbias/lstdbias/ldstbias/",
				substr($tzdef, $offset, 34)
			);
			$offset += 34;

			$rule['stStandardDate'] = unpack(
				"vyear/vmonth/vdayofweek/vday/vhour/vminute/vsecond/vmiliseconds/",
				substr($tzdef, $offset, 16)
			);
			$offset += 16;

			$rule['stDaylightDate'] = unpack(
				"vyear/vmonth/vdayofweek/vday/vhour/vminute/vsecond/vmiliseconds/",
				substr($tzdef, $offset, 16)
			);
			$offset += 16;

			$res['rules'][] = $rule;
		}

		return $res;
	}

	/**
	 * Converts timezone definition to timezonetag prop
	 * ("PT_BINARY:PSETID_Appointment:0x8233" aka
	 * "PT_BINARY:PSETID_Appointment:" . PidLidTimeZoneStruct)
	 * compatible structure.
	 *
	 * @param array $tzdef timezone definition array
	 *
	 * @return array
	 */
	public static function GetTzFromTimezoneDef($tzdef) {
		$tz = [];
		// convert timezone name because in zcore the chars are nul-padded
		if (!empty($tzdef['keyname'])) {
			$tz['tzname'] = $tz['tznamedst'] = self::getMSTZnameFromTZName(iconv('UTF-16', 'UTF-8', $tzdef['keyname']));
		}

		foreach ($tzdef['rules'] as $rule) {
			if ($rule['tzruleflags'] & TZRULE_FLAG_EFFECTIVE_TZREG) {
				$tz['bias'] = $rule['bias'];
				$tz['dstendyear'] = $rule['stStandardDate']['year'];
				$tz['dstendmonth'] = $rule['stStandardDate']['month'];
				$tz['dstendday'] = $rule['stStandardDate']['day'];
				$tz['dstendweek'] = $rule['stStandardDate']['dayofweek'];
				$tz['dstendhour'] = $rule['stStandardDate']['hour'];
				$tz['dstendminute'] = $rule['stStandardDate']['minute'];
				$tz['dstendsecond'] = $rule['stStandardDate']['second'];
				$tz['dstendmillis'] = $rule['stStandardDate']['miliseconds'];
				$tz['stdbias'] = $rule['stdbias'];
				$tz['dststartyear'] = $rule['stDaylightDate']['year'];
				$tz['dststartmonth'] = $rule['stDaylightDate']['month'];
				$tz['dststartday'] = $rule['stDaylightDate']['day'];
				$tz['dststartweek'] = $rule['stDaylightDate']['dayofweek'];
				$tz['dststarthour'] = $rule['stDaylightDate']['hour'];
				$tz['dststartminute'] = $rule['stDaylightDate']['minute'];
				$tz['dststartsecond'] = $rule['stDaylightDate']['second'];
				$tz['dststartmillis'] = $rule['stDaylightDate']['miliseconds'];
				$tz['dstbias'] = $rule['dstbias'];
			}
		}

		// Fallback if there isn't effective timezone
		if (!isset($tz['bias'])) {
			$tz = self::GetGMTTz();
		}

		// Make the structure compatible with class.recurrence.php
		$tz['timezone'] = $tz['bias'];
		$tz['timezonedst'] = $tz['dstbias'];

		return $tz;
	}
}
