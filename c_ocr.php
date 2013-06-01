<?php
namespace php_ocr\c_ocr;

/**
 * Class c_ocr
 * @package php_ocr\c_ocr
 */
class c_ocr
{
    /**
     * Изображение которое будем обрабатывать
     * @var resource
     */
    public static $img;
    /**
     * Массив с нарезаными строками текста
     * @var array
     */
    public static $img_line;
    /**
     * Массив рисунков символов для распознования
     * @var array array(GD)
     */
    public static $img_char;
    /**
     * Массив шаблонов символов для распознования
     * @var array array(GD)
     */
    public static $img_char_templates;

    /**
     * Хранит информацию о изображении
     * [0] ширина
     * [1] высота
     * [2] Тип рисунка png jpeg gif
     * @var array Массив полученый через функцию getimagesize
     */
    public static $img_info;

    /**
     * [pix][X][Y]=Значение индекса цвета в пикселе XxY в изображении
     * ['index'][index]=Количество цветов с таким индексом
     * ['count_pix']=Количество пикселей в изображении
     * ['percent'][index]= Процентное соотношение цветов на изображении
     * @var array
     */
    public static $colors_index;

    /**
     * @param string $img_file Имя файла с исображением
     * @return bool|resource
     */
    static function open_img($img_file)
    {
        self::$img_info=getimagesize($img_file);
        //Увеличиваем с каждой стороны на 4 пикселя чтоб избежать начала текста близко к краю изображения
        self::$img = imagecreatetruecolor(self::$img_info[0]+4, self::$img_info[1]+4);
        $white=imagecolorallocate(self::$img, 255, 255, 255);
        imagefill(self::$img, 0, 0, $white);
        switch(self::$img_info[2])
        {
            case IMAGETYPE_PNG :
                $tmp_img = imagecreatefrompng($img_file);
                break;
            case IMAGETYPE_JPEG :
                $tmp_img = imagecreatefromjpeg($img_file);
                break;
            case IMAGETYPE_GIF :
                $tmp_img = imagecreatefromgif($img_file);
                break;
            default:
                switch(true)
                {
                    case $tmp_img = @imagecreatefromstring($img_file): break;
                    case $tmp_img = @imagecreatefromgd($img_file): break;
                    default: return false;
                }
                break;
        }
        imagecopy(self::$img, $tmp_img, 2, 2, 0, 0, self::$img_info[0], self::$img_info[1]);
        self::$img=self::check_background_brightness(self::$img);
        return self::$img;
    }

    /**
     * Подсчитываем количество цветов в изображении и их долю в палитре
     * Сбор индексов цвета каждого пикселя
     * @param resource $img
     * @return array
     */
    static function get_colors_index($img)
    {
        $colors_index=array();
        $img_info[0]=imagesx($img);
        $img_info[1]=imagesy($img);
        for($x=0;$x<$img_info[0];$x++)
        {
            for($y=0;$y<$img_info[1];$y++)
            {
                $pixel_index=imagecolorat($img,$x,$y);
                $colors_index['pix'][$x][$y]=$pixel_index;
                if(isset($colors_index['index']) && array_key_exists($pixel_index,$colors_index['index']))
                    $colors_index['index'][$pixel_index]++;
                else $colors_index['index'][$pixel_index]=1;
            }
        }
        arsort($colors_index['index'],SORT_NUMERIC);
        $colors_index['count_pix']=$img_info[0]*$img_info[1];
        foreach ($colors_index['index'] as $key => $value)
        {
            $colors_index['percent'][$key]=($value/$colors_index['count_pix'])*100;
        }
        return $colors_index;
    }

    /**
     * Получаем индексы цветов текста и индекс цвета фона
     * @param resource $img
     * @return array
     */
    static function get_colors_index_text_and_background($img)
    {
        $count_colors=self::get_colors_index($img);
        reset($count_colors['index']);
        $background_index=key($count_colors['index']);
        unset($count_colors['index'][$background_index]);
        // Собираем все цвета отличные от фона
        if(self::$img_info[2]==IMAGETYPE_JPEG)
        {
            $background_brightness=self::get_brightness_to_index($background_index,$img);
            $background_brightness=$background_brightness-($background_brightness*0.2);
            foreach ($count_colors['index'] as $key => $value)
            {
                $color_brightness=self::get_brightness_to_index($key,$img);
                if($background_brightness<$color_brightness) unset($count_colors['index'][$key]);
            }
        }
        $indexes['text']=array_keys($count_colors['index']);
        $indexes['background']=$background_index;
        return $indexes;
    }

    /**
     * Вычисления цвета фона изображения с текстом, Фон светлее текста или наоборот, если темнее то цвета инвертируются
     * @param resource $img
     * @return resource
     */
    static function check_background_brightness($img)
    {
        $color_indexes=self::get_colors_index_text_and_background($img);
        $background_color=imagecolorsforindex($img, $color_indexes['background']);
        $brightness_background=($background_color['red']+$background_color['green']+$background_color['blue'])/3;
        $mid_color=self::get_mid_color_to_indexes($img,$color_indexes['text']);
        $brightness_text=($mid_color['red']+$mid_color['green']+$mid_color['blue'])/3;
        if($brightness_background<$brightness_text) imagefilter($img,IMG_FILTER_NEGATE); //Инвертируем если фон черный
        return $img;
    }

    /**
     * Подсчитывает средний цвет из массива индексов
     * @param resource $img
     * @param array $array_indexes
     * @return array
     */
    static function get_mid_color_to_indexes($img,$array_indexes)
    {
        $mid_color['red']=0;
        $mid_color['green']=0;
        $mid_color['blue']=0;
        foreach ($array_indexes as $key => $value)
        {
            $color=imagecolorsforindex($img, $key);
            $mid_color['red']+=$color['red'];
            $mid_color['green']+=$color['green'];
            $mid_color['blue']+=$color['blue'];
        }
        $count_indexes=count($array_indexes);
        foreach ($mid_color as &$value) $value/=$count_indexes; //Вычисляем средний цвет текста
        unset($value);
        return $mid_color;
    }

    /**
     * Разбивает рисунок на строки с текстом
     * @param resource $img
     * @return array
     */
    static function divide_to_line($img)
    {
        $img_info['x']=imagesx($img);
        $img_info['y']=imagesy($img);
        $coordinates=self::coordinates_img($img);
        $top_line=$coordinates['start'];
        $bottom_line=$coordinates['end'];
        // Ищем самую низкую строку для захвата заглавных букв
        $h_min=99999;
        foreach ($top_line as $key => $value)
        {
            $h_line=$bottom_line[$key]-$top_line[$key];
            if($h_min>$h_line) $h_min=$h_line;
        }
        // Увеличим все строки на треть самой маленькой для захвата заглавных букв м хвостов букв
        $change_size=0.35*$h_min;
        foreach ($top_line as $key => $value)
        {
            $top_line[$key]-=$change_size;
            $bottom_line[$key]+=$change_size;
        }
        // Нарезаем на полоски с текстом
        $img_line=array();
        foreach ($top_line as $key => $value)
        {
            $img_line[$key]=imagecreatetruecolor($img_info['x']+4, $bottom_line[$key]-$top_line[$key]+4);
            $white=imagecolorallocate($img_line[$key], 255, 255, 255);
            imagefill($img_line[$key], 0, 0, $white);
            imagecopy($img_line[$key],$img,2,2,0,$top_line[$key],$img_info['x'],$bottom_line[$key]-$top_line[$key]);
        }
        return $img_line;
    }

    /**
     * Разбиваем текстовые строки на слова
     * @param resource $img
     * @return array
     */
    static function divide_to_word($img)
    {
        $img_line=self::divide_to_line($img);
        $img_word=array();
        foreach ($img_line as $line_value)
        {
            $img_info['x']=imagesx($line_value);
            $img_info['y']=imagesy($line_value);
            $coordinates=self::coordinates_img($line_value,true);
            $begin_word=$coordinates['start'];
            $end_word=$coordinates['end'];
            // Нарезаем на слова
            foreach ($begin_word as $begin_key => $begin_value)
            {
                $img_word[]=imagecreatetruecolor($end_word[$begin_key]-$begin_value+4, $img_info['y']+4);
                end($img_word);
                $key_array_word=key($img_word);
                $white=imagecolorallocate($img_word[$key_array_word], 255, 255, 255);
                imagefill($img_word[$key_array_word], 0, 0, $white);
                imagecopy($img_word[$key_array_word],$line_value,2,2,$begin_value,0,$end_word[$begin_key]-$begin_value,$img_info['y']);
            }
        }
        return $img_word;
    }

    /**
     * Разбивает рисунок с текстом на маленькие рисунки с символом
     * @param resource $img
     * @return array
     */
    static function divide_to_char($img)
    {
        $img_word=self::divide_to_word($img);
        $img_char=array();
        foreach ($img_word as $word_value)
        {
            $img_info['x']=imagesx($word_value);
            $img_info['y']=imagesy($word_value);
            $coordinates=self::coordinates_img($word_value,true,1);
            $begin_char=$coordinates['start'];
            $end_word=$coordinates['end'];
            // Нарезаем на слова
            foreach ($begin_char as $begin_key => $begin_value)
            {
                $img_char[]=imagecreatetruecolor($end_word[$begin_key]-$begin_value+4, $img_info['y']+4);
                end($img_char);
                $key_array_word=key($img_char);
                $white=imagecolorallocate($img_char[$key_array_word], 255, 255, 255);
                imagefill($img_char[$key_array_word], 0, 0, $white);
                imagecopy($img_char[$key_array_word],$word_value,2,2,$begin_value,0,$end_word[$begin_key]-$begin_value,$img_info['y']);
            }
        }
        return $img_char;
    }

    /**
     * Поиск точек разделения изображения
     * @param resource $img Изображения для вычесления строк
     * @param bool $rotate Поворачивать изображени или нет
     * @param int $border Размер границы одной части текста до другой
     * @return array координаты для обрезания
     */
    static function coordinates_img($img,$rotate=false,$border=2)
    {
        if($rotate)
        {
            $white=imagecolorallocate($img, 255, 255, 255);
            $img=imagerotate($img , 270 , $white);
        }
        // Находим среднее значение яркости каждой пиксельной строки и всего рисунка
        $brightness_lines=array();
        $brightness_img=0;
        $bold_img=self::bold_text($img,'width');
        $colors_index_bold=self::get_colors_index($bold_img);
        $colors_index=self::get_colors_index($img);
        $img_info['x']=imagesx($bold_img);
        $img_info['y']=imagesy($bold_img);
        for($y=0;$y<$img_info['y'];$y++)
        {
            $brightness_lines[$y]=0;
            $brightness_lines_normal[$y]=0;
            for($x=0;$x<$img_info['x'];$x++)
            {
                $brightness_lines[$y]+=self::get_brightness_to_index($colors_index_bold['pix'][$x][$y],$bold_img);
                $brightness_lines_normal[$y]+=self::get_brightness_to_index($colors_index['pix'][$x][$y],$img);
            }
            $brightness_lines[$y]/=$img_info['x'];
            $brightness_img+=$brightness_lines_normal[$y]/$img_info['x'];
        }
        $brightness_img/=$img_info['y'];
        $coordinates['start']=array();
        $coordinates['end']=array();
        //Находим все верхние и нижние границы строк текста
        for($y=$border;$y<$img_info['y']-$border;$y++)
        {
            //Top
            if( $brightness_lines[$y-$border]>$brightness_img &&
                ($brightness_lines[$y-($border-1)]>$brightness_img || $border==1) &&
                $brightness_lines[$y]>$brightness_img &&
                ($brightness_lines[$y+($border-1)]<$brightness_img || $border==1) &&
                $brightness_lines[$y+$border]<$brightness_img
            )
                $coordinates['start'][]=$y;
            //Bottom
            elseif($brightness_lines[$y-$border]<$brightness_img &&
                ($brightness_lines[$y-($border-1)]<$brightness_img || $border==1) &&
                $brightness_lines[$y]>$brightness_img &&
                ($brightness_lines[$y+($border-1)]>$brightness_img || $border==1) &&
                $brightness_lines[$y+$border]>$brightness_img
            )
                $coordinates['end'][]=$y;
            elseif($brightness_lines[$y-$border]<$brightness_img &&
                $brightness_lines[$y]>$brightness_img &&
                $brightness_lines[$y+$border]<$brightness_img &&
                $border==1
            )
            {
                $coordinates['start'][]=$y;
                $coordinates['end'][]=$y;
            }
        }
        return $coordinates;
    }
    /**
     * Вычисляем яркость цвета по его индексу
     * @param int $color_index
     * @param resource $img
     * @return int
     */
    static function get_brightness_to_index($color_index,$img=null)
    {
        if($img===null) $img=self::$img;
        $color=imagecolorsforindex($img, $color_index);
        return ($color['red']+$color['green']+$color['blue'])/3;
    }

    /**
     * Заливаем текст для более точного определения по яркости
     * @param resource $img
     * @param string $b_type тип утолщения width height
     * @return resource
     */
    static function bold_text($img,$b_type='width')
    {
        $color_indexes=self::get_colors_index_text_and_background($img);
        $img_info['x']=imagesx($img);
        $img_info['y']=imagesy($img);
        $blur_img=imagecreatetruecolor($img_info['x'],$img_info['y']);
        imagecopy($blur_img, $img, 0, 0, 0, 0, $img_info['x'], $img_info['y']);
        $black=imagecolorallocate($blur_img, 0, 0, 0);
        $bold_size=10; //Величина утолщения
        for($x=0;$x<$img_info['x'];$x++)
        {
            for($y=0;$y<$img_info['y'];$y++)
            {
                if(array_search(imagecolorat($img,$x,$y),$color_indexes['text'])!==false)
                {
                    switch ($b_type)
                    {
                        case 'width':
                            imagefilledrectangle($blur_img,$x-$bold_size,$y,$x+$bold_size,$y,$black);
                            break;
                        case 'height':
                            imagefilledrectangle($blur_img,$x,$y-$bold_size,$x,$y+$bold_size,$black);
                            break;
                        default:
                            break;
                    }
                }
            }
        }
        return $blur_img;
    }
}
