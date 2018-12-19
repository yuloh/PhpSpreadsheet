<?php

namespace PhpOffice\PhpSpreadsheet\Collection;

use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Exception as PhpSpreadsheetException;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Psr\SimpleCache\CacheInterface;

class Cells
{
    /**
     * Parent worksheet.
     *
     * @var Worksheet
     */
    private $parent;

    /**
     * The currently active Cell.
     *
     * @var Cell
     */
    private $currentCell;

    /**
     * Coordinate of the currently active Cell.
     *
     * @var string
     */
    private $currentCoordinate;

    /**
     * Flag indicating whether the currently active Cell requires saving.
     *
     * @var bool
     */
    private $currentCellIsDirty = false;

    /**
     * An index of existing cells. Cells indexed by their coordinate.
     *
     * @var Cell[]
     */
    private $index = [];

    /**
     * Initialise this new cell collection.
     *
     * @param Worksheet $parent The worksheet for this cell collection
     */
    public function __construct(Worksheet $parent)
    {
        // Set our parent worksheet.
        // This is maintained here to facilitate re-attaching it to Cell objects when
        // they are woken from a serialized state
        $this->parent = $parent;
    }

    /**
     * Return the parent worksheet for this cell collection.
     *
     * @return Worksheet
     */
    public function getParent()
    {
        return $this->parent;
    }

    /**
     * Whether the collection holds a cell for the given coordinate.
     *
     * @param string $pCoord Coordinate of the cell to check
     *
     * @return bool
     */
    public function has($pCoord)
    {
        if ($pCoord === $this->currentCoordinate) {
            return true;
        }

        // Check if the requested entry exists in the index
        return isset($this->index[$pCoord]);
    }

    /**
     * Add or update a cell in the collection.
     *
     * @param Cell $cell Cell to update
     *
     * @throws PhpSpreadsheetException
     *
     * @return Cell
     */
    public function update(Cell $cell)
    {
        return $this->add($cell->getCoordinate(), $cell);
    }

    /**
     * Delete a cell in cache identified by coordinate.
     *
     * @param string $pCoord Coordinate of the cell to delete
     */
    public function delete($pCoord)
    {
        if ($pCoord === $this->currentCoordinate && $this->currentCell !== null) {
            $this->currentCell->detach();
            $this->currentCoordinate = null;
            $this->currentCell = null;
            $this->currentCellIsDirty = false;
        }

        unset($this->index[$pCoord]);
    }

    /**
     * Get a list of all cell coordinates currently held in the collection.
     *
     * @return string[]
     */
    public function getCoordinates()
    {
        return array_keys($this->index);
    }

    /**
     * Get a sorted list of all cell coordinates currently held in the collection by row and column.
     *
     * @return string[]
     */
    public function getSortedCoordinates()
    {
        $sortKeys = [];
        foreach ($this->getCoordinates() as $coord) {
            $column = '';
            $row = 0;
            sscanf($coord, '%[A-Z]%d', $column, $row);
            $sortKeys[sprintf('%09d%3s', $row, $column)] = $coord;
        }
        ksort($sortKeys);

        return array_values($sortKeys);
    }

    /**
     * Get highest worksheet column and highest row that have cell records.
     *
     * @return array Highest column name and highest row number
     */
    public function getHighestRowAndColumn()
    {
        // Lookup highest column and highest row
        $col = ['A' => '1A'];
        $row = [1];
        foreach ($this->getCoordinates() as $coord) {
            $c = '';
            $r = 0;
            sscanf($coord, '%[A-Z]%d', $c, $r);
            $row[$r] = $r;
            $col[$c] = strlen($c) . $c;
        }

        // Determine highest column and row
        $highestRow = max($row);
        $highestColumn = substr(max($col), 1);

        return [
            'row' => $highestRow,
            'column' => $highestColumn,
        ];
    }

    /**
     * Return the cell coordinate of the currently active cell object.
     *
     * @return string
     */
    public function getCurrentCoordinate()
    {
        return $this->currentCoordinate;
    }

    /**
     * Return the column coordinate of the currently active cell object.
     *
     * @return string
     */
    public function getCurrentColumn()
    {
        $column = '';
        $row = 0;

        sscanf($this->currentCoordinate, '%[A-Z]%d', $column, $row);

        return $column;
    }

    /**
     * Return the row coordinate of the currently active cell object.
     *
     * @return int
     */
    public function getCurrentRow()
    {
        $column = '';
        $row = 0;

        sscanf($this->currentCoordinate, '%[A-Z]%d', $column, $row);

        return (int) $row;
    }

    /**
     * Get highest worksheet column.
     *
     * @param string $row Return the highest column for the specified row,
     *                    or the highest column of any row if no row number is passed
     *
     * @return string Highest column name
     */
    public function getHighestColumn($row = null)
    {
        if ($row == null) {
            $colRow = $this->getHighestRowAndColumn();

            return $colRow['column'];
        }

        $columnList = [1];
        foreach ($this->getCoordinates() as $coord) {
            $c = '';
            $r = 0;

            sscanf($coord, '%[A-Z]%d', $c, $r);
            if ($r != $row) {
                continue;
            }
            $columnList[] = Coordinate::columnIndexFromString($c);
        }

        return Coordinate::stringFromColumnIndex(max($columnList) + 1);
    }

    /**
     * Get highest worksheet row.
     *
     * @param string $column Return the highest row for the specified column,
     *                       or the highest row of any column if no column letter is passed
     *
     * @return int Highest row number
     */
    public function getHighestRow($column = null)
    {
        if ($column == null) {
            $colRow = $this->getHighestRowAndColumn();

            return $colRow['row'];
        }

        $rowList = [0];
        foreach ($this->getCoordinates() as $coord) {
            $c = '';
            $r = 0;

            sscanf($coord, '%[A-Z]%d', $c, $r);
            if ($c != $column) {
                continue;
            }
            $rowList[] = $r;
        }

        return max($rowList);
    }

    /**
     * Clone the cell collection.
     *
     * @param Worksheet $parent The new worksheet that we're copying to
     *
     * @return self
     */
    public function cloneCellCollection(Worksheet $parent)
    {
        $this->storeCurrentCell();
        $newCollection = clone $this;

        $newCollection->parent = $parent;
        if (($newCollection->currentCell !== null) && (is_object($newCollection->currentCell))) {
            $newCollection->currentCell->attach($this);
        }

        return $newCollection;
    }

    /**
     * Remove a row, deleting all cells in that row.
     *
     * @param string $row Row number to remove
     */
    public function removeRow($row)
    {
        foreach ($this->getCoordinates() as $coord) {
            $c = '';
            $r = 0;

            sscanf($coord, '%[A-Z]%d', $c, $r);
            if ($r == $row) {
                $this->delete($coord);
            }
        }
    }

    /**
     * Remove a column, deleting all cells in that column.
     *
     * @param string $column Column ID to remove
     */
    public function removeColumn($column)
    {
        foreach ($this->getCoordinates() as $coord) {
            $c = '';
            $r = 0;

            sscanf($coord, '%[A-Z]%d', $c, $r);
            if ($c == $column) {
                $this->delete($coord);
            }
        }
    }

    /**
     * Store cell data in cache for the current cell object if it's "dirty",
     * and the 'nullify' the current cell object.
     *
     * @throws PhpSpreadsheetException
     */
    private function storeCurrentCell()
    {
        if ($this->currentCellIsDirty && !empty($this->currentCoordinate)) {
            $this->currentCell->detach();

            $this->index[$this->currentCoordinate] = $this->currentCell;
            $this->currentCellIsDirty = false;
        }

        $this->currentCoordinate = null;
        $this->currentCell = null;
    }

    /**
     * Add or update a cell identified by its coordinate into the collection.
     *
     * @param string $pCoord Coordinate of the cell to update
     * @param Cell $cell Cell to update
     *
     * @throws PhpSpreadsheetException
     *
     * @return \PhpOffice\PhpSpreadsheet\Cell\Cell
     */
    public function add($pCoord, Cell $cell)
    {
        if ($pCoord !== $this->currentCoordinate) {
            $this->storeCurrentCell();
        }
        $this->index[$pCoord] = $cell;

        $this->currentCoordinate = $pCoord;
        $this->currentCell = $cell;
        $this->currentCellIsDirty = true;

        return $cell;
    }

    /**
     * Get cell at a specific coordinate.
     *
     * @param string $pCoord Coordinate of the cell
     *
     * @throws PhpSpreadsheetException
     *
     * @return \PhpOffice\PhpSpreadsheet\Cell\Cell Cell that was found, or null if not found
     */
    public function get($pCoord)
    {
        if ($pCoord === $this->currentCoordinate) {
            return $this->currentCell;
        }
        $this->storeCurrentCell();

        // Return null if requested entry doesn't exist in collection
        if (!$this->has($pCoord)) {
            return null;
        }

        // Set current entry to the requested entry
        $this->currentCoordinate = $pCoord;
        $this->currentCell = $this->index[$pCoord];
        // Re-attach this as the cell's parent
        $this->currentCell->attach($this);

        // Return requested entry
        return $this->currentCell;
    }

    /**
     * Clear the cell collection and disconnect from our parent.
     */
    public function unsetWorksheetCells()
    {
        if ($this->currentCell !== null) {
            $this->currentCell->detach();
            $this->currentCell = null;
            $this->currentCoordinate = null;
        }

        $this->index = [];

        // detach ourself from the worksheet, so that it can then delete this object successfully
        $this->parent = null;
    }
}
