<?php
	//error_reporting(E_ALL);
	class Currency
	{
		function __construct()
		{
		}
		function get_bd_money_format($amount)
		{
			$output_string = '';
			$fraction = '';
			$tokens = explode('.', $amount);
			$number = $tokens[0];
			if(count($tokens) > 1)
			{
				$fraction = (double)('0.' . $tokens[1]);
				$fraction = $fraction * 100;
				$fraction = round($fraction, 0);
				$fraction = '.' . $fraction;
			}
			$number = $number . '';
			$spl=str_split($number);
			$lpcount=count($spl);
			$rem=$lpcount-3;
			if($lpcount%2==0)
			{
				for($i=0;$i<=$lpcount-1;$i++)
				{
					if($i%2!=0 && $i!=0 && $i!=$lpcount-1)
					{
						$output_string .= ",";
					}
					$output_string .= $spl[$i];
				}
			}
			if($lpcount%2!=0)
			{
				for($i=0;$i<=$lpcount-1;$i++)
				{
					if($i%2==0 && $i!=0 && $i!=$lpcount-1)
					{
						$output_string .= ",";
					}
					$output_string .= $spl[$i];
				}
			}
			return $output_string . $fraction;
		}
		function translate_to_words($number)
		{
			$max_size = pow(10,18);
			if (!$number) return "zero";
			if (is_int($number) && $number < abs($max_size))
			{
				$prefix = '';
				$suffix = '';
				switch ($number)
				{
					case $number < 0:
						$prefix = "negative";
						$suffix = $this->translate_to_words(-1*$number);
						$string = $prefix . " " . $suffix;
						break;
					case 1:
						$string = "one";
						break;
					case 2:
						$string = "two";
						break;
					case 3:
						$string = "three";
						break;
					case 4:
						$string = "four";
						break;
					case 5:
						$string = "five";
						break;
					case 6:
						$string = "six";
						break;
					case 7:
						$string = "seven";
						break;
					case 8:
						$string = "eight";
						break;
					case 9:
						$string = "nine";
						break;
					case 10:
						$string = "ten";
						break;
					case 11:
						$string = "eleven";
						break;
					case 12:
						$string = "twelve";
						break;
					case 13:
						$string = "thirteen";
						break;
					case 15:
						$string = "fifteen";
						break;
					case $number < 20:
						$string = $this->translate_to_words($number%10);
						if ($number == 18)
						{
						$suffix = "een";
						} else 
						{
						$suffix = "teen";
						}
						$string .= $suffix;
						break;
					case 20:
						$string = "twenty";
						break;
					case 30:
						$string = "thirty";
						break;
					case 40:
						$string = "forty";
						break;
					case 50:
						$string = "fifty";
						break;
					case 60:
						$string = "sixty";
						break;
					case 70:
						$string = "seventy";
						break;
					case 80:
						$string = "eighty";
						break;
					case 90:
						$string = "ninety";
						break;
					case $number < 100:
						$prefix = $this->translate_to_words($number-$number%10);
						$suffix = $this->translate_to_words($number%10);
						//$string = $prefix . "-" . $suffix;
						$string = $prefix . " " . $suffix;
						break;
					// handles all number 100 to 999
					case $number < pow(10,3):
						// floor return a float not an integer
						$prefix = $this->translate_to_words(intval(floor($number/pow(10,2)))) . " hundred";
						if ($number%pow(10,2)) $suffix = " and " . $this->translate_to_words($number%pow(10,2));
						$string = $prefix . $suffix;
						break;
					case $number < pow(10,6):
						// floor return a float not an integer
						$prefix = $this->translate_to_words(intval(floor($number/pow(10,3)))) . " thousand";
						if ($number%pow(10,3)) $suffix = $this->translate_to_words($number%pow(10,3));
						$string = $prefix . " " . $suffix;
						break;
				}
			} else
			{
				echo "ERROR with - $number<br/> Number must be an integer between -" . number_format($max_size, 0, ".", ",") . " and " . number_format($max_size, 0, ".", ",") . " exclussive.";
			}
			return $string;
		}
		function get_bd_amount_in_text($amount)
		{
			$output_string = '';
			$tokens = explode('.', $amount);
			$current_amount = $tokens[0];
			$fraction = '';
			if(count($tokens) > 1)
			{
				$fraction = (double)('0.' . $tokens[1]);
				$fraction = $fraction * 100;
				$fraction = round($fraction, 0);
				$fraction = (int)$fraction;
				if ($fraction > 0) 
				{
				   $fraction = $this->translate_to_words($fraction) . ' paisa';
				   $fraction = ' and ' . $fraction;
				}
				else
				{
				   $fraction = '';
				}
			}
			$crore = 0;
			if($current_amount >= pow(10,7))
			{
				$crore = (int)floor($current_amount / pow(10,7));
				$output_string .= $this->translate_to_words($crore) . ' crore ';
				$current_amount = $current_amount - $crore * pow(10,7);
			}
			$lakh = 0;
			if($current_amount >= pow(10,5))
			{
				$lakh = (int)floor($current_amount / pow(10,5));
				$output_string .= $this->translate_to_words($lakh) . ' lakh ';
				$current_amount = $current_amount - $lakh * pow(10,5);
			}
			$current_amount = (int)$current_amount;
			$output_string .= $this->translate_to_words($current_amount);
			$output_string = 'Rupees '. $output_string . $fraction . ' only';
			$output_string = ucwords($output_string);
			return $output_string;
		}
		function get_number_to_text($amount)
		{
			$output_string = '';
			$tokens = explode('.', $amount);
			$current_amount = $tokens[0];
			$fraction = '';
			if(count($tokens) > 1)
			{
				$fraction = (double)('0.' . $tokens[1]);
				$fraction = $fraction * 100;
				$fraction = round($fraction, 0);
				$fraction = (int)$fraction;
				if ($fraction > 0) 
				{
				   $fraction = $this->translate_to_words($fraction) . ' paisa';
				   $fraction = ' and ' . $fraction;
				}
				else
				{
				   $fraction = '';
				}
			}
			$crore = 0;
			if($current_amount >= pow(10,7))
			{
				$crore = (int)floor($current_amount / pow(10,7));
				$output_string .= $this->translate_to_words($crore) . ' crore ';
				$current_amount = $current_amount - $crore * pow(10,7);
			}
			$lakh = 0;
			if($current_amount >= pow(10,5))
			{
				$lakh = (int)floor($current_amount / pow(10,5));
				$output_string .= $this->translate_to_words($lakh) . ' lakh ';
				$current_amount = $current_amount - $lakh * pow(10,5);
			}
			$current_amount = (int)$current_amount;
			$output_string .= $this->translate_to_words($current_amount);
			$output_string = $output_string;
			$output_string = ucwords($output_string);
			return $output_string;
		}
	}
?>
