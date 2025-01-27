#!/usr/bin/php
<?php

/*
OpenHeatMap processing
Copyright (C) 2010 Pete Warden <pete@petewarden.com>

    This program is free software: you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation, either version 3 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program.  If not, see <http://www.gnu.org/licenses/>.
*/

require_once('cliargs.php');
require_once('osmways.php');

function convert_unemployment_file($input_file_name, $output_file_name, $bla_to_fips, $osm_ways)
{
    $input_file_handle = fopen($input_file_name, "r") or die("Couldn't open $input_file_name\n");
    $output_file_handle = fopen($output_file_name, "w") or die("Couldn't open $output_file_name\n");

    fwrite($output_file_handle, '"state_code","county_code","time","value"'."\n");

    $remove_duplicate_series = false;
    $found_codes_for_area = array();

    $line_index = 0;
    while(!feof($input_file_handle))
    {
        $current_line = fgets($input_file_handle);
        $current_line = trim($current_line);

        $current_line = preg_replace('/[ \t]+/', ' ', $current_line);
        
        $input_parts = explode(' ', $current_line);
        
        if ((count($input_parts)!=4)&&
            (count($input_parts)!=5))
            continue;
            
        $first_part = $input_parts[0];

        $series_code = substr($first_part, 0, 5);
        if (($series_code!=='LAUCN')&&
            ($series_code!=='LAUPS')&&
            ($series_code!=='LAUCT')&&
            ($series_code!=='LAUPA'))
        {
//            error_log('Bad series code found: '.$series_code);
            continue;
        }

        $bla_code = substr($first_part, 5, 5);
            
        $value_type = substr($first_part, 10, 3);

        if (!isset($bla_to_fips[$bla_code]))
        {
//            error_log("Missing FIPS code for $bla_code");
            $fips_code = $bla_code;
        }
        else
        {
            $info_list = $bla_to_fips[$bla_code];
            $fips_code = null;
            foreach ($info_list as $fips_info)
            {
                $full_series_code = 'LAU'.$fips_info['series_code'];
                if (empty($fips_code)||
                    ($full_series_code==$series_code))
                    $fips_code = $fips_info['fips_code'];
            }
            
        }
        
        if ($remove_duplicate_series)
        {
            if (isset($found_codes_for_area[$fips_code])&&
                ($found_codes_for_area[$fips_code]!==$series_code))
                continue;
                
            $found_codes_for_area[$fips_code] = $series_code;
        }

        $state_code = substr($fips_code, 0, 2);
        $county_code = substr($fips_code, 2, 3);
        
        if (isset($osm_ways))
        {
            $matching_ways = $osm_ways->get_ways_matching_keys(array(
                'state_code' => $state_code,
                'county_code' => $county_code,
            ));
        
            if (empty($matching_ways))
                continue;        
        }
        
        if ($value_type!=='003')
        {
//            error_log('Bad value type found: '.$value_type);
            continue;
        }
            
        $year = (int)($input_parts[1]);
        
        if ($year<2005)
            continue;
        
        $month_string = $input_parts[2];
        $month_code = substr($month_string, 0, 1);
        if ($month_code!=='M')
        {
//            error_log('Bad month code found: '.$month_code);
            continue;
        }
            
        $month_value = (substr($month_string, 1, 2));
        if ($month_value>12)
            continue;

        $time_string = $year.'-'.$month_value;
            
        $unemployment_percentage = (float)($input_parts[3]);
        
        $output_parts = array(
            $state_code,
            $county_code,
            $time_string,
            $unemployment_percentage,
        );
        
        fputcsv($output_file_handle, $output_parts);
    }
    
    fclose($input_file_handle);
    fclose($output_file_handle);
}

function load_bla_to_fips($file_name)
{
    $file_handle = fopen($file_name, "r") or die("Couldn't open $file_name\n");

    $result = array();

    $line_index = 0;
    while(!feof($file_handle))
    {
        $current_parts = fgetcsv($file_handle);
        
        $line_index += 1;
        if ($line_index<2)
            continue;

        $bla_code = $current_parts[0];
        $fips_code = $current_parts[1];
        $series_code = $current_parts[2];

        if (!isset($result[$bla_code]))
            $result[$bla_code] = array();

        $result[$bla_code][] = array(
            'fips_code' => $fips_code,
            'series_code' => $series_code,
        );
    }
    
    fclose($file_handle);
    
    return $result;
}

$cliargs = array(
	'inputfile' => array(
		'short' => 'i',
		'type' => 'optional',
		'description' => 'The folder containing the unemployment data from the BLS - if unset, will read from stdin',
        'default' => 'php://stdin',
	),
	'blatofipsfile' => array(
		'short' => 'b',
		'type' => 'required',
		'description' => 'The file containing the mapping of BLA codes to FIPS',
	),
	'outputfile' => array(
		'short' => 'o',
		'type' => 'optional',
		'description' => 'The file to write the output csv data to - if unset, will write to stdout',
        'default' => 'php://stdout',
	),
	'waysfile' => array(
		'short' => 'w',
		'type' => 'optional',
		'description' => 'A file containing the way shapes that this data will be drawn onto',
        'default' => '',
	),
);	

$options = cliargs_get_options($cliargs);

$input_file = $options['inputfile'];
$output_file = $options['outputfile'];
$bla_to_fips_file = $options['blatofipsfile'];
$ways_file = $options['waysfile'];

if (!empty($ways_file))
{
    $osm_ways = new OSMWays();
    $osm_xml_string = file_get_contents($ways_file) or die("Couldn't open '$ways_file' for reading");
    $osm_ways->deserialize_from_xml($osm_xml_string);
}
else
{
    $osm_ways = null;
}

$bla_to_fips = load_bla_to_fips($bla_to_fips_file);

convert_unemployment_file($input_file, $output_file, $bla_to_fips, $osm_ways);

?>